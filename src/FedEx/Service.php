<?php
/**
 * Created by PhpStorm.
 * User: johan
 * Date: 2017-03-03
 * Time: 19:24
 */
declare(strict_types = 1);

namespace Vinnia\Shipping\FedEx;

use Closure;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\Promise;
use function GuzzleHttp\Promise\promise_for;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Money\Currency;
use Money\Money;
use Psr\Http\Message\ResponseInterface;
use Vinnia\Shipping\Address;
use Vinnia\Shipping\ExportDeclaration;
use Vinnia\Shipping\QuoteRequest;
use Vinnia\Shipping\ServiceException;
use Vinnia\Shipping\Shipment;
use Vinnia\Shipping\Parcel;
use Vinnia\Shipping\Quote;
use Vinnia\Shipping\ServiceInterface;
use DateTimeImmutable;
use SimpleXMLElement;
use Vinnia\Shipping\ShipmentRequest;
use Vinnia\Shipping\Tracking;
use Vinnia\Shipping\TrackingActivity;
use Vinnia\Shipping\Xml;
use Vinnia\Util\Arrays;
use Vinnia\Util\Collection;
use Vinnia\Util\Measurement\Amount;
use Vinnia\Util\Measurement\Unit;
use Vinnia\Util\Validation\Validator;

class Service implements ServiceInterface
{

    const URL_TEST = 'https://wsbeta.fedex.com:443/web-services';
    const URL_PRODUCTION = 'https://ws.fedex.com:443/web-services';

    /**
     * @var ClientInterface
     */
    private $guzzle;

    /**
     * @var Credentials
     */
    private $credentials;

    /**
     * @var string
     */
    private $url;

    function __construct(ClientInterface $guzzle, Credentials $credentials, string $url = self::URL_PRODUCTION)
    {
        $this->guzzle = $guzzle;
        $this->credentials = $credentials;
        $this->url = $url;
    }

    /**
     * @param Address $address
     * @return array
     */
    private function addressToArray(Address $address): array
    {
        // fedex only supports 2 street lines so
        // let's put everything that overflows
        // into the 2nd line.
        $lines = [
            $address->lines[0] ?? '',
            implode(', ', array_slice($address->lines, 1)),
        ];
        return [
            'StreetLines' => array_map([Xml::class, 'cdata'], array_filter($lines)),
            'City' => Xml::cdata($address->city),
            'StateOrProvinceCode' => Xml::cdata($address->state),
            'PostalCode' => $address->zip,
            'CountryCode' => $address->countryCode,
            'Residential' => null,
        ];
    }

    /**
     * @param string $endpoint
     * @param string $body
     * @param Closure $success
     * @param Closure|null $error
     * @return PromiseInterface
     */
    private function send(string $endpoint, string $body, Closure $success, Closure $error = null): PromiseInterface
    {
        return $this->guzzle->requestAsync('POST', $this->url . $endpoint, [
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then($success, $error);
    }

    /**
     * @param QuoteRequest $request
     * @return PromiseInterface
     */
    public function getQuotes(QuoteRequest $request): PromiseInterface
    {
        $parcels = array_map(function (Parcel $parcel, int $idx) use ($request): array {
            $parcel = $request->units == QuoteRequest::UNITS_IMPERIAL ?
                $parcel->convertTo(Unit::INCH, Unit::POUND) :
                $parcel->convertTo(Unit::CENTIMETER, Unit::KILOGRAM);

            return [
                'SequenceNumber' => $idx + 1,
                'GroupNumber' => 1,
                'GroupPackageCount' => 1,
                'Weight' => [
                    'Units' => $request->units == ShipmentRequest::UNITS_IMPERIAL ? 'LB' : 'KG',
                    'Value' => $parcel->weight->format(2),
                ],
                'Dimensions' => [
                    'Length' => $parcel->length->format(0),
                    'Width' => $parcel->width->format(0),
                    'Height' => $parcel->height->format(0),
                    'Units' => $request->units == ShipmentRequest::UNITS_IMPERIAL ? 'IN' : 'CM',
                ],
            ];
        }, $request->parcels, array_keys($request->parcels));

        $sender = $request->sender;
        $recipient = $request->recipient;

        $rateRequest = Xml::fromArray([
            'RateRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'crs',
                    'Major' => 22,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'RequestedShipment' => [
                    'DropoffType' => 'REGULAR_PICKUP',
                    'PackagingType' => 'YOUR_PACKAGING',
                    'Shipper' => [
                        'Address' => $this->addressToArray($sender),
                    ],
                    'Recipient' => [
                        'Address' => $this->addressToArray($recipient),
                    ],
                    'ShippingChargesPayment' => [
                        'PaymentType' => $request->shipmentPaymentType === ShipmentRequest::PAYMENT_TYPE_SENDER ?
                            'SENDER' :
                            'RECIPIENT',
                        'Payor' => [
                            'ResponsibleParty' => [
                                'AccountNumber' => $this->credentials->getAccountNumber(),
                            ],
                        ],
                    ],
                    'RateRequestTypes' => 'NONE',
                    'PackageCount' => count($request->parcels),
                    'RequestedPackageLineItems' => $parcels,
                ],
            ],
        ]);

        $body = <<<EOD
<p:Envelope xmlns:p="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/rate/v22">
   <p:Body>{$rateRequest}</p:Body>
</p:Envelope>
EOD;

        return $this->send('/rate', $body, function (ResponseInterface $response) {
            $body = (string) $response->getBody();

            $xml = new SimpleXMLElement($body, LIBXML_PARSEHUGE);
            $details = $xml->xpath('/SOAP-ENV:Envelope/SOAP-ENV:Body/*[local-name()=\'RateReply\']/*[local-name()=\'RateReplyDetails\']');

            return array_map(function (SimpleXMLElement $element): Quote {
                $product = (string) $element->{'ServiceType'};

                $total = $element
                    ->{'RatedShipmentDetails'}
                    ->{'ShipmentRateDetail'}
                    ->{'TotalNetChargeWithDutiesAndTaxes'};

                $amountString = (string) $total->{'Amount'};
                $amount = (int) round(((float) $amountString) * pow(10, 2));

                return new Quote('FedEx', $product, new Money($amount, new Currency((string) $total->{'Currency'})));
            }, $details);
        });
    }

    /**
     * @param string $trackingNumber
     * @param array $options
     * @return PromiseInterface
     */
    public function getTrackingStatus(string $trackingNumber, array $options = []): PromiseInterface
    {
        $trackRequest = Xml::fromArray([
            'TrackRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'trck',
                    'Major' => 14,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'SelectionDetails' => [
                    'PackageIdentifier' => [
                        'Type' => 'TRACKING_NUMBER_OR_DOORTAG',
                        'Value' => $trackingNumber,
                    ],
                ],
                'ProcessingOptions' => 'INCLUDE_DETAILED_SCANS',
            ],
        ]);

        $body = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/track/v14">
   <soapenv:Header />
   <soapenv:Body>{$trackRequest}</soapenv:Body>
</soapenv:Envelope>
EOD;

        return $this->send('/track', $body, function (ResponseInterface $response) {
            $body = (string) $response->getBody();
            $xml = new SimpleXMLElement($body, LIBXML_PARSEHUGE);
            $arrayed = Xml::toArray($xml->xpath('/SOAP-ENV:Envelope/SOAP-ENV:Body')[0]);

            $validator = new Validator([
                'TrackReply.CompletedTrackDetails.TrackDetails.Notification.Severity' => 'required|ne:ERROR',
                'TrackReply.CompletedTrackDetails.TrackDetails.Events' => 'array',
                'TrackReply.CompletedTrackDetails.TrackDetails.Service.Type' => 'required|string',
            ]);

            $bag = $validator->validate($arrayed);

            if (count($bag) !== 0) {
                return new RejectedPromise($body);
            }

            $service = (string) Arrays::get($arrayed, 'TrackReply.CompletedTrackDetails.TrackDetails.Service.Type');
            $events = Arrays::get($arrayed, 'TrackReply.CompletedTrackDetails.TrackDetails.Events');

            if (!Xml::isNumericKeyArray($events)) {
                $events = [$events];
            }

            $activities = (new Collection($events))->map(function (array $element) {
                $status = $this->getStatusFromEventType((string) $element['EventType']);
                $description = $element['EventDescription'];
                $dt = new DateTimeImmutable($element['Timestamp']);
                $address = new Address(
                    '',
                    [],
                    $element['Address']['PostalCode'] ?? '',
                    $element['Address']['City'] ?? '',
                    $element['Address']['StateOrProvinceCode'] ?? '',
                    $element['Address']['CountryName'] ?? ''
                );

                return new TrackingActivity($status, $description, $dt, $address);
            })->value();

            return new Tracking('FedEx', $service, $activities);
        });
    }

    /**
     * @param string $type
     * @return int
     */
    private function getStatusFromEventType(string $type): int
    {
        $type = mb_strtoupper($type, 'utf-8');

        // status mappings stolen from keeptracker.
        $typeMap = [
            TrackingActivity::STATUS_DELIVERED => [
                'DL',
            ],
            TrackingActivity::STATUS_EXCEPTION => [
                // cancelled
                'CA',

                // general issues
                'CD', 'DY', 'DE', 'HL', 'CH', 'SE',

                // returned to shipper
                'RS',
            ],
        ];

        foreach ($typeMap as $status => $types) {
            if (in_array($type, $types)) {
                return $status;
            }
        }

        return TrackingActivity::STATUS_IN_TRANSIT;
    }

    /**
     * @param ShipmentRequest $request
     * @return PromiseInterface
     * @throws Exception
     */
    public function createShipment(ShipmentRequest $request): PromiseInterface
    {
        /* @var Amount $totalWeight */
        $totalWeight = array_reduce($request->parcels, function (Amount $carry, Parcel $current) use ($request): Amount {
            $parcel = $request->units == QuoteRequest::UNITS_IMPERIAL ?
                $current->convertTo(Unit::INCH, Unit::POUND) :
                $current->convertTo(Unit::CENTIMETER, Unit::KILOGRAM);

            return new Amount($carry->getValue() + $parcel->weight->getValue(), $parcel->weight->getUnit());
        }, new Amount(0, ''));

        /* @var Shipment[] $shipments */
        $shipments = [];

        $masterTrackingId = null;

        // if this shipment contains multiple parcels we need
        // to send one request per parcel. if one request fails
        // we need to cancel the other shipments.
        foreach ($request->parcels as $idx => $parcel) {
            $body = $this->buildShipmentRequestBody($request, $idx, $totalWeight, $masterTrackingId);

            try {
                /* @var Shipment $shipment */
                $shipment = $this->send('/ship', $body, function (ResponseInterface $response) {
                    return $this->parseShipmentRequestResponse($response);
                })->wait();

                $masterTrackingId = $masterTrackingId ?? $shipment->id;

                $shipments[] = $shipment;
            } catch (Exception $e) {
                // if one parcel fails we need to rollback the other shipments
                foreach ($shipments as $shipment) {
                    $this->cancelShipment($shipment->id, [
                        'type' => 'FEDEX',
                    ])->wait();
                }

                throw $e;
            }
        }

        return promise_for($shipments);
    }

    protected function buildShipmentRequestBody(
        ShipmentRequest $request,
        int $parcelIndex,
        Amount $totalWeight,
        ?string $masterTrackingId = null
    ): string
    {
        $parcel = $request->units == QuoteRequest::UNITS_IMPERIAL ?
            $request->parcels[$parcelIndex]->convertTo(Unit::INCH, Unit::POUND) :
            $request->parcels[$parcelIndex]->convertTo(Unit::CENTIMETER, Unit::KILOGRAM);

        $data = [
            'ProcessShipmentRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'ship',
                    'Major' => 21,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'RequestedShipment' => [
                    'ShipTimestamp' => $request->date->format('c'),
                    'DropoffType' => 'REGULAR_PICKUP',
                    'ServiceType' => $request->service,
                    'PackagingType' => 'YOUR_PACKAGING',
                    'TotalWeight' => $parcelIndex === 0 ? [
                        'Units' => $totalWeight->getUnit() === Unit::POUND ? 'LB' : 'KG',
                        'Value' => $totalWeight->format(2),
                    ] : null,
                    'TotalInsuredValue' => [
                        'Currency' => $request->currency,
                        'Amount' => number_format($request->insuredValue, 2, '.', ''),
                    ],
                    'Shipper' => [
                        'Contact' => [
                            'PersonName' => Xml::cdata($request->sender->contactName),
                            'CompanyName' => Xml::cdata($request->sender->name),
                            'PhoneNumber' => Xml::cdata($request->sender->contactPhone),
                        ],
                        'Address' => $this->addressToArray($request->sender),
                    ],
                    'Recipient' => [
                        'Contact' => [
                            'PersonName' => Xml::cdata($request->recipient->contactName),
                            'CompanyName' => Xml::cdata($request->recipient->name),
                            'PhoneNumber' => Xml::cdata($request->recipient->contactPhone),
                        ],
                        'Address' => $this->addressToArray($request->recipient),
                    ],
                    'ShippingChargesPayment' => [
                        'PaymentType' => $request->shipmentPaymentType === ShipmentRequest::PAYMENT_TYPE_SENDER ?
                            'SENDER' :
                            'RECIPIENT',
                        'Payor' => [
                            'ResponsibleParty' => [
                                'AccountNumber' => $this->credentials->getAccountNumber(),
                            ],
                        ],
                    ],
                    'SpecialServicesRequested' => [
                        'SpecialServiceTypes' => $request->specialServices,
                    ],
                    'CustomsClearanceDetail' => [
                        'DutiesPayment' => [
                            'PaymentType' => $request->dutyPaymentType === ShipmentRequest::PAYMENT_TYPE_SENDER ?
                                'SENDER' :
                                'RECIPIENT',
                        ],
                        'CustomsValue' => [
                            'Currency' => $request->currency,
                            'Amount' => number_format($request->value, 2, '.', ''),
                        ],
                        'CommercialInvoice' => [
                            'TermsOfSale' => $request->incoterm,
                        ],
                        'Commodities' => array_map(function (ExportDeclaration $decl) use ($request) {
                            return [
                                'NumberOfPieces' => $decl->quantity,
                                'Description' => Xml::cdata($decl->description),
                                'CountryOfManufacture' => $decl->originCountryCode,
                                'Weight' => [
                                    'Units' => $request->units == ShipmentRequest::UNITS_IMPERIAL ? 'LB' : 'KG',
                                    'Value' => $decl->weight
                                        ->convertTo($request->units == ShipmentRequest::UNITS_IMPERIAL ? Unit::POUND : Unit::KILOGRAM)
                                        ->format(2)
                                ],
                                'Quantity' => $decl->quantity,
                                'QuantityUnits' => 'Pieces',
                                'UnitPrice' => [
                                    'Currency' => $decl->currency,
                                    'Amount' => number_format($decl->value / $decl->quantity, 2, '.', ''),
                                ],
                            ];
                        }, $request->exportDeclarations),
                    ],
                    'LabelSpecification' => [
                        'LabelFormatType' => 'COMMON2D',
                        'ImageType' => $request->labelFormat ?? 'PDF',
                        'LabelStockType' => $request->labelSize ?? 'PAPER_LETTER',
                    ],
                    'ShippingDocumentSpecification' => [],
                    'MasterTrackingId' => $parcelIndex === 0 ? null : [
                        'TrackingNumber' => $masterTrackingId,
                    ],
                    'PackageCount' => count($request->parcels),
                    'RequestedPackageLineItems' => [
                        [
                            'SequenceNumber' => $parcelIndex + 1,
                            'GroupNumber' => 1,
                            'GroupPackageCount' => 1,
                            'Weight' => [
                                'Units' => $request->units == ShipmentRequest::UNITS_IMPERIAL ? 'LB' : 'KG',
                                'Value' => $parcel->weight->format(2),
                            ],
                            'Dimensions' => [
                                'Length' => $parcel->length->format(0),
                                'Width' => $parcel->width->format(0),
                                'Height' => $parcel->height->format(0),
                                'Units' => $request->units == ShipmentRequest::UNITS_IMPERIAL ? 'IN' : 'CM',
                            ],
                            'CustomerReferences' => [
                                'CustomerReferenceType' => 'CUSTOMER_REFERENCE',
                                'Value' => $request->reference,
                            ],
                            'SpecialServicesRequested' => [
                                'SpecialServiceTypes' => [
                                    $request->signatureRequired ? 'SIGNATURE_OPTION' : null,
                                ],
                                'SignatureOptionDetail' => $request->signatureRequired ? [
                                    'OptionType' => 'DIRECT',
                                ] : null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($request->extra as $key => $value) {
            Arrays::set($data, $key, $value);
        }

        $data = Xml::removeKeysWithEmptyValues($data);
        $shipRequest = Xml::fromArray($data);

        $body = <<<EOD
<p:Envelope xmlns:p="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/ship/v21">
   <p:Body>$shipRequest</p:Body>
</p:Envelope>
EOD;

        return $body;
    }

    protected function parseShipmentRequestResponse(ResponseInterface $response): Shipment
    {
        $body = (string) $response->getBody();

        // remove namespace prefixes to ease parsing
        $body = str_replace('SOAP-ENV:', '', $body);

        if ($this->isErrorResponse($body)) {
            $this->throwError($body);
        }

        preg_match('/<TrackingIds>.*<TrackingNumber>([^<]+)</', $body, $matches);

        $trackingNumber = $matches[1];

        preg_match('/<Label>.*<Image>([^<]+)</', $body, $matches);

        $image = base64_decode($matches[1]);

        return new Shipment($trackingNumber, 'FedEx', $image, $body);
    }

    /**
     * @param string $id
     * @param array $data
     * @return PromiseInterface
     */
    public function cancelShipment(string $id, array $data = []): PromiseInterface
    {
        $deleteRequest = Xml::fromArray([
            'DeleteShipmentRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'ship',
                    'Major' => 21,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'TrackingId' => [
                    'TrackingIdType' => $data['type'],
                    'TrackingNumber' => $id,
                ],
                'DeletionControl' => 'DELETE_ALL_PACKAGES',
            ],
        ]);

        $body = <<<EOD
<p:Envelope xmlns:p="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/ship/v21">
   <p:Body>$deleteRequest</p:Body>
</p:Envelope>
EOD;

        return $this->send('/ship', $body, function (ResponseInterface $response) {
            $body = (string) $response->getBody();

            return $body;
        });
    }

    protected function throwError(string $body)
    {
        $xml = new SimpleXMLElement($body);
        $arrayed = Xml::toArray($xml);
        $notifications = Arrays::get($arrayed, 'Body.ProcessShipmentReply.Notifications');

        // when we convert XML-formatted data to an
        // array we can't really be sure which elements
        // may have multiple occurrences. in this case
        // we know that there may be multiple notifications.
        if (!Xml::isNumericKeyArray($notifications)) {
            $notifications = [$notifications];
        }

        $errors = array_map(function (array $notification): string {
            return $notification['Message'];
        }, $notifications);

        throw new ServiceException($errors, $body);
    }

    /**
     * @param QuoteRequest $request
     * @return PromiseInterface promise resolved with an array of strings
     */
    public function getAvailableServices(QuoteRequest $request): PromiseInterface
    {
        $data = [
            'ServiceAvailabilityRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'vacs',
                    'Major' => 8,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'Origin' => [
                    'PostalCode' => $request->sender->zip,
                    'CountryCode' => $request->sender->countryCode,
                ],
                'Destination' => [
                    'PostalCode' => $request->recipient->zip,
                    'CountryCode' => $request->recipient->countryCode,
                ],
                'ShipDate' => $request->date->format('Y-m-d'),
            ]
        ];

        $xml = Xml::fromArray($data);
        $body = <<<EOD
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/vacs/v8">
   <soapenv:Body>$xml</soapenv:Body>
</soapenv:Envelope>
EOD;

        return $this->send('/vacs', $body, function (ResponseInterface $response) {
            $body = (string) $response->getBody();
            $body = str_replace('SOAP-ENV:', '', $body);
            $xml = new SimpleXMLElement($body);
            $arrayed = Xml::toArray($xml);
            $services = Arrays::get($arrayed, 'Body.ServiceAvailabilityReply.Options') ?? [];

            if (!Xml::isNumericKeyArray($services)) {
                $services = [$services];
            }

            return (new Collection($services))->map(function (array $service): string {
                return $service['Service'];
            })->value();
        });
    }

    /**
     * @param string $body
     * @return bool
     */
    protected function isErrorResponse(string $body): bool
    {
        return preg_match('/<HighestSeverity>(FAILURE|ERROR)<\/HighestSeverity>/', $body) === 1;
    }

}
