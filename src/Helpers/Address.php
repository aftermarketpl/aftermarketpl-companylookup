<?php 

namespace Aftermarketpl\CompanyLookup\Helpers;

use Aftermarketpl\CompanyLookup\Helpers\Validator; 
use Aftermarketpl\CompanyLookup\Exceptions\AddressException;

class Address 
{
    public static function extract(string $country, string $address): array
    {
        $city = $zip = "";
        $lines = explode("\n", $address);

        $lines = array_filter($lines, function ($line)
        {
            return !empty(trim($line));
        });

        $zipRegex = Validator::getZipRegex($country);
        if(!$zipRegex)
            return [
                str_replace("\n", " ", $address),
                $zip,
                $city,
            ];

        switch ($country)
        {
            case "GB": //Great Britain
                $zip = array_pop($lines);
                $city = array_pop($lines);
                $address = join(" ", $lines);
                break;
            case "MT": //Malta
                $city = array_pop($lines);
                $zip = array_pop($lines);
                $address = join(" ", $lines);
                break;
            case "AT": //Austria
            case "CY": //Cyprus
            case "PL": //Poland
            case "BE": //Belgium
            case "FI": //Finland
            case "FR": //France
            case "LU": //Luxemburg
            case "PT": //Portugal
            case "IT": //Italy
                $codeAndCityLine = array_pop($lines);
                $codeAndCity = explode(" ", $codeAndCityLine, 2);
                $zip = $codeAndCity[0];
                $city = $codeAndCity[1];
                $address = join(" ", $lines);
                break;
            case "EE": //Estonia
                preg_match("/^(?<addr>.*)\s+(?<zip>$zipRegex)\s(?<city>.*?)$/", array_pop($lines), $matches);
                if (preg_match("/^(.*?)\s\\1$/", $matches["city"], $matches2))
                    $matches["city"] = $matches2[1];
                $lines[] = $matches["addr"];
                $address = join(" ", $lines);
                $zip = $matches["zip"];
                $city = $matches["city"];
                break;
            case "LV": //Latvia
                preg_match("/^(.*),\s(.*?),\s(?<zip>$zipRegex)$/", $lines[0], $matches);
                $address = $matches[1];
                $city = $matches[2];
                $zip = $matches["zip"];
                break;
            case "SE": //Sweden
            case "CZ": //Czech R.
            case "DK": //Denmark
            case "NL": //Netherlands
                $lastLine = array_pop($lines);
                preg_match("/^(?<zip>$zipRegex)\s(?<city>.*)$/", $lastLine, $matches);
                $address = join(" ", $lines);
                $city = $matches["city"];
                $zip = $matches["zip"];
                break;
            case "SI": //Slovenia
            case "HR": //Croatia
                preg_match("/^(.*),\s(?<zip>$zipRegex)\s(?<city>.*?)$/", $lines[0], $matches);
                $address = $matches[1];
                $zip = $matches["zip"];
                $city = $matches["city"];
                break;
            case "SK": //Slovakia
                array_pop($lines); //last line is country
                $codeAndCityLine = array_pop($lines);
                $codeAndCity = explode(" ", $codeAndCityLine, 2);
                $zip = $codeAndCity[0];
                $city = $codeAndCity[1];
                $address = join(" ", $lines);
                break;
            case "HU": //Hungary
                //preg_match("/^(?<zip>$zipRegex)\s(?<city>.*?)\s(?<addr>.*)$/", array_pop($lines), $matches);
                preg_match("/^(?<addr>.*)\s(?<zip>$zipRegex)\s(?<city>.*?)$/", array_pop($lines), $matches);
                $zip = $matches["zip"] ?? "";
                $city = $matches["city"] ?? "";
                $lines[] = $matches["addr"] ?? "";
                $address = join(" ", $lines);
                break;
            case "BG": //Bulgaria
                $codeAndCityLine = array_pop($lines);
                preg_match("/^(?<addr>.*,\s)?(?<city>.*?)\s(?<zip>$zipRegex)$/", $codeAndCityLine, $matches);
                $city = $matches["city"];
                $zip = $matches["zip"];
                $lines[] = $matches["addr"];
                $address = join(" ", $lines);
                break;
            case "GR": //Greece
                $codeAndCityLine = array_pop($lines);
                preg_match("/^(?<addr>.*)\s(?<zip>$zipRegex) - (?<city>.*?)$/", $codeAndCityLine, $matches);
                $city = $matches["city"];
                $zip = $matches["zip"];
                $lines[] = $matches["addr"];
                $address = join(" ", $lines);
                break;
            //IE, LT, DE, RO, ES - can't find example with zip
        }

        try
        {
            if ($zip && Validator::checkZip($zip, $country))
                return [$address, $zip, $city];
        }
        catch (Error_Task_Fatal $e) {}

        //empty
        return [
            str_replace("\n", " ", $address),
            $zip,
            $city,
        ];
    }
}
