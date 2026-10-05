<?php
declare(strict_types=1);

use Aftermarketpl\CompanyLookup\IdentifierType;
use Aftermarketpl\CompanyLookup\Models\CompanyIdentifier;
use PHPUnit\Framework\TestCase;
use Aftermarketpl\CompanyLookup\Env;

final class GusTest extends TestCase
{
    public static $reader = null;
    
    /**
     * Bootstrap VAT reader class
     */
    public static function setUpBeforeClass(): void
    {
        self::$reader = new Aftermarketpl\CompanyLookup\GusReader(Env::$gusapikey);
    }

    public function testCorrectNip()
    {
        $response = self::$reader->lookup('7282697380');
        $this->assertTrue($response->valid);
    }

    public function testInvalidType()
    {
        $this->expectExceptionMessage('Identifier type \'VAT\' is not supported');
        self::$reader->lookup('7282697380', IdentifierType::VAT);
    }

    public function testExceptionInvalidNip()
    {
        $this->expectExceptionMessage('Incorrect NIP self sign value');
        $response = self::$reader->lookup('1234567899', IdentifierType::NIP);
        $this->assertTrue($response->valid);
    }

    public function testExceptionWhenWIthNipWithPrefix()
    {
        $this->expectExceptionMessage('NIP should have 10 digits, 12 given');
        $response = self::$reader->lookup('PL7282697380', IdentifierType::NIP);
        $this->assertTrue($response->valid);
    }

    public function testCorrectRegon()
    {
        $response = self::$reader->lookup('022434610', IdentifierType::REGON);
        $this->assertTrue($response->valid);
    } 
    
    public function testIncorrectRegon()
    {
        $response = self::$reader->lookup('022434610111', IdentifierType::REGON);
        $this->assertFalse($response->valid);
    }

    public function testCorrectKrs()
    {
        $response = self::$reader->lookup('0000513708', IdentifierType::KRS);
        $this->assertTrue($response->valid);
    } 
    
    public function testIncorrectKrs()
    {
        $response = self::$reader->lookup('513708111', IdentifierType::KRS);
        $this->assertFalse($response->valid);
    }

    public function testKrsIdentifier()
    {
        $response = self::$reader->lookup('0000513708', IdentifierType::KRS);
        $this->assertTrue($response->valid);
        $krsIdentifier = array_filter(
            $response->identifiers,
            function (CompanyIdentifier $identifier) {
                return $identifier->type == IdentifierType::KRS;
            }
        );
        $this->assertEquals("0000513708", reset($krsIdentifier)->id);
    }

    public function testStartDate()
    {
        $response = self::$reader->lookup('7282697380');
        $this->assertTrue($response->valid);
        $this->assertTrue(strtotime($response->startDate) !== false);
    }

    public function testStartDate2()
    {
        $response = self::$reader->lookup('9121874990', IdentifierType::NIP);
        $this->assertTrue($response->valid);
        $this->assertTrue(strtotime($response->startDate) !== false);
    }

    public function testOrganizationKRS(): void
    {
        $response = self::$reader->lookup('7231629144');
        $this->assertTrue($response->valid);
        $krsIdentifier = array_filter(
            $response->identifiers,
            function (CompanyIdentifier $identifier) {
                return $identifier->type == IdentifierType::NIP;
            }
        );

        $this->assertEquals("7231629144", reset($krsIdentifier)->id);
    }


    public function testEmptyStreetAddress(): void
    {
        $response = self::$reader->lookup('7581005177');
        $mainAddress = $response->mainAddress;
        $this->assertNotEmpty($mainAddress);
        $this->assertEquals('ul. Starowiejska 15', $mainAddress->address);
    }

    public function testDeleted(): void
    {
        $response = self::$reader->lookup('9590824065');
        $this->assertFalse($response->valid);
    }


    public function testWebsiteAddress(): void
    {
        $response = self::$reader->lookup('7542935038');
        $this->assertTrue($response->valid);
        $this->assertEquals(['szic.pl'], $response->websiteAddresses);
    }

    public function testCompanyClosedNip()
    {
        $response = self::$reader->lookup('5252389922', IdentifierType::NIP);
        $this->assertFalse($response->valid);
    }

}