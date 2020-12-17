<?php 

namespace Aftermarketpl\CompanyLookup;

use Aftermarketpl\CompanyLookup\Exceptions\GusReaderException;
use Aftermarketpl\CompanyLookup\Models\CompanyAddress;
use Aftermarketpl\CompanyLookup\Models\CompanyData;
use Aftermarketpl\CompanyLookup\Models\CompanyIdentifier;
use Aftermarketpl\CompanyLookup\Models\CompanyRepresentative;
use Aftermarketpl\CompanyLookup\Validators;
use GusApi\Exception\InvalidUserKeyException;
use GusApi\Exception\NotFoundException;
use GusApi\GusApi;
use GusApi\ReportTypes;
use GusApi\SearchReport;

/**
 * https://api.stat.gov.pl/Home/RegonApi/
 */
class GusReader implements Reader
{
    use Traits\ResolvesVatid;
    use Traits\ValidatesVatid;

    /**
     * API key
     */
    private $apikey = '';
    private $api = null;

    private $options = [];
    
    /**
     * Handle current report
     */
    private $report = false;


    /**
     * 
     */
    public function __construct(string $apikey = '')
    {
        $this->apikey = $apikey;

        try {
            $this->api = new GusApi($apikey);
            $this->api->login();
        } catch(\Throwable $e) {
            throw new GusReaderException('Checking status currently not available');
        }
        
    }

    /**
     * Lookup company
     */
    public function lookup(string $id, string $type = IdentifierType::NIP) : Companydata
    {
        switch ($type) {
            case IdentifierType::NIP:
                return $this->lookupNIP($id);
            case IdentifierType::REGON:
                return $this->lookupREGON($id);
            case IdentifierType::KRS:
                return $this->lookupKRS($id);
            default:
                throw new GusReaderException(sprintf('Identifier type \'%s\' is not supported', $type));
        }
    }

    /**
     * Lookup company by vatid
     */
    private function lookupNIP(string $nip) : Companydata
    {
        Validators\PL::checkNip($nip);

        try {
            $gusReports = $this->api->getByNip($nip);

            foreach ($gusReports as $gusReport) {
                if($gusReport->getActivityEndDate())
                    continue; // ommit inactive
                
                $this->report = $gusReport;
            }
            // inactive, but in results
            if($gusReport) {
                $this->report = $gusReport;
            }

            return $this->mapCompanyData($this->report);
        } catch (InvalidUserKeyException $e) {
            throw new GusReaderException('Checking status currently not available [Invalid Api key]');
        
        } catch (NotFoundException $e) {
            $companyData = new CompanyData;
            $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::NIP, $nip);
            $companyData->valid = false;
            return $companyData;
        }
    }

    /**
     * Lookup company by KRS
     */
    private function lookupKRS(string $krs)
    {
        $companyData = new CompanyData;

        try {
            $gusReports = $this->api->getByKrs($krs);

            foreach ($gusReports as $gusReport) {
                if($gusReport->getActivityEndDate())
                    continue; // ommit inactive
                
                $this->report = $gusReport;
                $companyData =  $this->mapCompanyData($gusReport);
                return $companyData;
            }
            // inactive, but in results
            if($gusReport) {
                $this->report = $gusReport;
                return $this->mapCompanyData($gusReport);
            }

        } catch (InvalidUserKeyException $e) {
            throw new GusReaderException('Checking status currently not available [Invalid Api key]');
        
        } catch (NotFoundException $e) {
            $companyData = new CompanyData;
            $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::KRS, $krs);
            $companyData->valid = false;
            return $companyData;        
        }

        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::KRS, $krs);
        $companyData->valid = false;
        return $companyData;         
    }

    /**
     * Lookup company by REGON
     */
    private function lookupREGON(string $regon)
    {
        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::REGON, $regon);

        try {
            $gusReports = $this->api->getByRegon($regon);

            foreach ($gusReports as $gusReport) {
                if($gusReport->getActivityEndDate())
                    continue; // ommit inactive

                $this->report = $gusReport;
                return $this->mapCompanyData($gusReport);
            }

            // inactive, but in results
            if($gusReport) {
                $this->report = $gusReport;
                $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::REGON, $regon);
                return $this->mapCompanyData($gusReport);
            }

        } catch (InvalidUserKeyException $e) {
            throw new GusReaderException('Checking status currently not available [Invalid Api key]');
        
        } catch (NotFoundException $e) {
            $companyData = new CompanyData;
            $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::REGON, $regon);
            $companyData->valid = false;
            return $companyData;       
        }

        $companyData = new CompanyData;
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::REGON, $regon);
        $companyData->valid = false;
        return $companyData;         
    }

    protected function mapCompanyData(SearchReport $gusReport) : CompanyData {
        $activityReport = $this->getActivityReport($this->report);

        $companyAddress = new CompanyAddress;
        $companyAddress->country = 'PL';
        $companyAddress->postalCode = (string) $gusReport->getZipCode();
        $companyAddress->address = (string) (empty($gusReport->getStreet()) ? $gusReport->getCity() : $gusReport->getStreet()).' '.$gusReport->getPropertyNumber() . ( $gusReport->getApartmentNumber() ? '/'.$gusReport->getApartmentNumber() : '');
        $companyAddress->city = (string) $gusReport->getCity();

        $companyData = new CompanyData;
        if($gusReport->getActivityEndDate())
            $companyData->valid = false;
        else 
            $companyData->valid = true;

        $companyData->name = (string) $gusReport->getName();
        $companyData->startDate = $this->getField($activityReport[0], 'dataRozpoczeciaDzialalnosci');

        $companyData->representatives = $this->getRepresentatives($this->report);

        $companyData->identifiers = [];
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::NIP, $gusReport->getNip());
        $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::REGON, $gusReport->getRegon());

        $krsIdentifier = $this->getField($activityReport[0], 'numerWRejestrzeEwidencji');
        if ($krsIdentifier) {
            $companyData->identifiers[] = new CompanyIdentifier(IdentifierType::KRS, $krsIdentifier);
        }
        $websiteAddress = $this->getField($activityReport[0], 'adresStronyinternetowej');
        if ($websiteAddress) {
            $companyData->websiteAddresses[] = $websiteAddress;
        }
        $emailAddress = $this->getField($activityReport[0], 'adresEmail');
        if ($emailAddress) {
            $companyData->emailAddresses[] = $emailAddress;
        }
        $telephoneNumber = $this->getField($activityReport[0], 'numerTelefonu');
        if ($telephoneNumber) {
            $companyData->phoneNumbers[] = $telephoneNumber;
        }
        $faxNumber = $this->getField($activityReport[0], 'numerFaksu');
        if ($faxNumber) {
            $companyData->faxNumbers[] = $faxNumber;
        }

        $companyData->mainAddress = $companyAddress;

        $companyData->pkdCodes = array_map(function($v) {
            if(isset($v['fiz_pkd_Kod']))
                return $v['fiz_pkd_Kod'];
            if(isset($v['praw_pkdKod']))
                return $v['praw_pkdKod'];
        }, $this->fetchPKD());

        return $companyData;
    }

    private function fetchPKD()  {
        if(! ($this->report instanceof SearchReport)) {
            throw new GusReaderException('No company, please lookup company');
        }
        
        switch($this->report->getType()) {
            case 'p': // osoba prawna
                $reportType = ReportTypes::REPORT_ACTIVITY_LAW_PUBLIC;
                break;

            case 'f': // osoba fizyczna
                $reportType = ReportTypes::REPORT_LOCALS_PHYSIC_PUBLIC;
                break; 
            
            default:
                throw new GusReaderException('Uknown company type');
        }
        
        return $this->api->getFullReport($this->report, $reportType);
    }

    protected function getActivityReport(SearchReport $report): array
    {
        switch($report->getType()) {
            case 'p': // osoba prawna
                $reportType = ReportTypes::REPORT_PUBLIC_LAW;
                break;

            case 'f': // osoba fizyczna
                switch ($report->getSilo()) {
                    case 1:
                        $reportType = ReportTypes::REPORT_ACTIVITY_PHYSIC_CEIDG;
                        break;
                    case 2:
                        $reportType = ReportTypes::REPORT_ACTIVITY_PHYSIC_AGRO;
                        break;
                    case 3:
                        $reportType = ReportTypes::REPORT_ACTIVITY_PHYSIC_OTHER_PUBLIC;
                        break;
                    case 4:
                        $reportType = ReportTypes::REPORT_ACTIVITY_LOCAL_PHYSIC_WKR_PUBLIC;
                        break;
                    default:
                        throw new GusReaderException("Invalid SiloId");
                }
                break;
            default:
                throw new GusReaderException("Invalid SiloId");
        }

        return $this->api->getFullReport($report, $reportType);
    }

    private function getRepresentatives(SearchReport $report): array
    {
        $representatives = [];

        if ($report->getType() == 'f') {
            $reportType = ReportTypes::REPORT_ACTIVITY_PHYSIC_PERSON;
            $report = $this->api->getFullReport($report, $reportType);

            foreach ($report as $personReport) {
                if (empty($personReport["fiz_nazwisko"])) {
                    continue;
                }

                $new = new CompanyRepresentative($personReport["fiz_imie1"], $personReport["fiz_imie2"], $personReport["fiz_nazwisko"]);
                $same = array_filter($representatives, function (CompanyRepresentative $existing) use ($new) {
                    return $existing->equals($new);
                });

                if (empty($same)) {
                    $representatives[] = $new;
                }
            }

            return $representatives;
        }

        if ($report->getType() == 'p') {
            $reportType = ReportTypes::REPORT_COMMON_LAW_PUBLIC;
            $report = $this->api->getFullReport($report, $reportType);

            foreach ($report as $personReport) {
                if (empty($personReport["wspolsc_nazwisko"])) {
                    continue;
                }

                $new = new CompanyRepresentative(
                    $personReport["wspolsc_imiePierwsze"],
                    $personReport["wspolsc_imieDrugie"],
                    $personReport["wspolsc_nazwisko"]
                );

                $representatives[] = $new;
            }

            return $representatives;
        }

        throw new GusReaderException("Invalid SiloId");
    }

    private function getField(array $data, string $field): ?string
    {
        $name = sprintf('praw_%s', $field);
        if (!empty($data[$name])) {
            return $data[$name];
        }

        $name = sprintf('fiz_%s', $field);
        if (!empty($data[$name])) {
            return $data[$name];
        }

        return null;
    }
}