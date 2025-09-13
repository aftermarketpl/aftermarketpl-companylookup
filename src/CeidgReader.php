<?php 

namespace Aftermarketpl\CompanyLookup;

use Aftermarketpl\CompanyLookup\Exceptions\CeidgReaderException;
use Aftermarketpl\CompanyLookup\Models\CompanyAddress;
use Aftermarketpl\CompanyLookup\Models\CompanyData;
use Aftermarketpl\CompanyLookup\Models\CompanyIdentifier;
use Aftermarketpl\CompanyLookup\Models\CompanyRepresentative;
use SoapClient;

/**
 * https://datastore.ceidg.gov.pl/CEIDG.DataStore/Styles/Regulations/API_Datastore_20190314.pdf
 * 
 */
class CeidgReader implements Reader
{
    use Traits\ResolvesVatid;
    use Traits\ValidatesVatid;

    private $url = 'https://dane.biznes.gov.pl/api/ceidg/v3';
    private $apiKey = '';

    public function __construct(string $apikey = '')
    {
        $this->apiKey = $apikey;
    }

    /**
     * API Lookup
     */
    public function lookup(string $id, string $type = IdentifierType::NIP) : Companydata
    {
        switch ($type) {
            case IdentifierType::NIP:
                return $this->lookupNIP($id);
            case IdentifierType::REGON:
                return $this->lookupREGON($id);
            default:
                throw new CeidgReaderException(sprintf('Identifier type \'%s\' is not supported', $type));
        }
    }

    /**
     * @return CompanyData[]
     */
    public function lookupPartnership(string $id, string $type = IdentifierType::NIP): array
    {
        switch ($type) {
            case IdentifierType::NIP:
                return $this->lookupPartnershipNIP($id);
            case IdentifierType::REGON:
                return $this->lookupPartnershipREGON($id);
            default:
                throw new CeidgReaderException(sprintf('Identifier type \'%s\' is not supported', $type));
        }
    }

    /**
     * @return CompanyData[]
     */
    public function search(array $parameters): array
    {
        $parameters = array_filter($parameters);
        if (empty($parameters)) {
            throw new CeidgReaderException('Empty search parameters');
        }

        $supportedParameters = ['name'];

        $unknownParameters = array_diff(array_keys($parameters), $supportedParameters);
        if (!empty($unknownParameters)) {
            throw new CeidgReaderException(sprintf('Unsupported paremeters: %s, supported are: %s', implode(',', $unknownParameters), implode(',', $supportedParameters)));
        }

        $apiCallParameters = [];
        if (!empty($parameters['name'])) {
            $apiCallParameters['nazwa'] = $parameters['name'];
        }

        $json = $this->callApi('/firmy', $apiCallParameters);
        if(!$json) {
            $companyData = new CompanyData();
            $companyData->valid = false;
            return [$companyData];
        }

        $ids = array_map(function($entry) { return $entry['id']; }, $json['firmy']);
        $companiesData = $this->callApi('/firma/', ['ids' => $ids]);

        $companies = [];
        foreach($companiesData['firma'] as $wpis) {
            $companies[] = $this->parseCompanyData($wpis);
        }

        return $companies;
    }

    private function lookupNIP(string $nip) : Companydata
    {
        Validators\PL::checkNip($nip);

        $json = $this->callApi('/firma', [
            'nip' => $nip,
        ]);

        if(!$json) {
            return $this->createInvalidCompany(new CompanyIdentifier(IdentifierType::NIP, $nip));
        }

        // Find active company
        $resolvedCompany = false;
        $wpis = false;
        foreach($json['firma'] as $wpis) {
            if($wpis['status'] == 'AKTYWNY') {
                $resolvedCompany = $wpis;
            }
        }

        // Jeżeli nic nie zwrócono, budujemy wpis invalid
        if(!$resolvedCompany && !$wpis) {
            return $this->createInvalidCompany(new CompanyIdentifier(IdentifierType::NIP, $nip));
        } elseif(!$resolvedCompany) {
            $resolvedCompany = $wpis;
        }

        return $this->parseCompanyData($resolvedCompany);
    }

    private function lookupREGON(string $regon): Companydata
    {
        $json = $this->callApi('/firma', [
            'regon' => $regon,
        ]);

        if(!$json) {
            return $this->createInvalidCompany(new CompanyIdentifier(IdentifierType::REGON, $regon));
        }

        // Find active company
        $resolvedCompany = false;
        $wpis = false;
        foreach($json['firma'] as $wpis) {
            if($wpis['status'] == 'AKTYWNY') {
                $resolvedCompany = $wpis;
            }
        }

        // Jeżeli nic nie zwrócono, budujemy wpis invalid
        if(!$resolvedCompany && !$wpis) {
            return $this->createInvalidCompany(new CompanyIdentifier(IdentifierType::REGON, $regon));
        } elseif(!$resolvedCompany) {
            $resolvedCompany = $wpis;
        }

        return $this->parseCompanyData($resolvedCompany);
    }

    /**
     * @return CompanyData[]
     */
    private function lookupPartnershipNIP(string $nip): array
    {
        Validators\PL::checkNip($nip);

        $json = $this->callApi('/firmy', [
            'nip_sc' => $nip,
        ]);

        if(!$json) {
            return [$this->createInvalidCompany(new CompanyIdentifier(IdentifierType::NIP, $nip))];
        }

        $ids = array_map(function($entry) { return $entry['id']; }, $json['firmy']);
        $companiesData = $this->callApi('/firma/', ['ids' => $ids]);

        $companies = [];
        foreach($companiesData['firma'] as $wpis) {
            $companies[] = $this->parseCompanyData($wpis);
        }

        return $companies;
    }

    /**
     * @return CompanyData[]
     */
    private function lookupPartnershipREGON(string $regon): array
    {
        $json = $this->callApi('/firmy', [
            'regon_sc' => $regon,
        ]);

        if(!$json) {
            return [$this->createInvalidCompany(new CompanyIdentifier(IdentifierType::REGON, $regon))];
        }

        $ids = array_map(function($entry) { return $entry['id']; }, $json['firmy']);
        $companiesData = $this->callApi('/firma/', ['ids' => $ids]);

        $companies = [];
        foreach($companiesData['firma'] as $wpis) {
            $companies[] = $this->parseCompanyData($wpis);
        }

        return $companies;
    }

    private function callApi(string $path, array $parameters): array
    {
        $url = sprintf("%s%s?%s", $this->url, $path, http_build_query($parameters));

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->apiKey]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_error($curl)) {
            throw new CeidgReaderException(curl_error($curl), curl_errno($curl));
        }

        if (in_array($httpCode, [400, 204])) {
            return [];
        }

        if ($httpCode !== 200) {
            throw new CeidgReaderException("Invalid http response code", $httpCode);
        }

        $responseData = @json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CeidgReaderException("Invalid json response", $httpCode);
        }

        return $responseData;
    }

    private function createInvalidCompany(CompanyIdentifier $identifier): CompanyData
    {
        $companyData = new CompanyData;
        $companyData->identifiers[] = $identifier;
        $companyData->valid = false;

        return $companyData;
    }

    private function parseCompanyData(array $json): CompanyData
    {
        $companyData = new CompanyData;

        if(!empty($json['adresDzialalnosci']))
            $companyData->mainAddress =  $this->parseAddress($json['adresDzialalnosci']);
        if(!empty($json['adresKorespondencyjny']))
            $companyData->additionalAddresses[] = $this->parseAddress($json['adresKorespondencyjny']);
        if(!empty($json['adresyDzialalnosciDodatkowe'])) {
            foreach($json['adresyDzialalnosciDodatkowe'] as $jsonAddress) {
                $companyData->additionalAddresses[] = $this->parseAddress($jsonAddress);
            }
        }

        $validStatuses = [
            'AKTYWNY',
            'WYLACZNIE_W_FORMIE_SPOLKI',
        ];
        $companyData->valid = in_array($json['status'], $validStatuses);
        $companyData->name = $json['nazwa'];

        $companyData->identifiers = [];
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::NIP, $json['wlasciciel']['nip']);
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::REGON, $json['wlasciciel']['regon']);
        $companyData->startDate = $json['dataRozpoczecia'];
        $companyData->pkdCodes = $json['pkd'] ?? [];

        if(!empty($json['telefon']))
            $companyData->phoneNumbers = [$json['telefon']];

        if(!empty($json['email']))
            $companyData->emailAddresses = [$json['email']];

        if(!empty($json['www']))
            $companyData->websiteAddresses = [$json['www']];

        $companyData->representatives[] = new CompanyRepresentative(
            $json['wlasciciel']['imie'],
            null,
            $json['wlasciciel']['nazwisko']
        );

        return $companyData;
    }

    private function parseAddress(array $address): CompanyAddress
    {
        $companyAddress = new CompanyAddress();
        $companyAddress->country = 'PL';
        $companyAddress->postalCode = $address['kod'];
        $companyAddress->city = $address['miasto'];

        if(!empty($address['lokal']))
            $companyAddress->address = sprintf("%s %s m. %s", $address['ulica'], $address['budynek'], $address['lokal']);
        else if(!empty($address['ulica']))
            $companyAddress->address = sprintf("%s %s", $address['ulica'], $address['budynek']);
        else
            $companyAddress->address = sprintf("%s %s", $address['miasto'], $address['budynek']);

        return $companyAddress;
    }
}