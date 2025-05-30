<?php
declare(strict_types=1);

use Aftermarketpl\CompanyLookup\Exceptions\KasReaderException;
use Aftermarketpl\CompanyLookup\IdentifierType;
use Aftermarketpl\CompanyLookup\KasReader;
use Aftermarketpl\CompanyLookup\Models\CompanyIdentifier;
use PHPUnit\Framework\TestCase;

final class KasTest extends TestCase
{
    public static $reader = null;

    public static function setUpBeforeClass(): void
    {
        self::$reader = new KasReader();
    }

    public function testCorrectNip()
    {
        $response = self::$reader->lookup('7282697380');
        $this->assertTrue($response->valid);
    }

    public function testInvalidType()
    {
        $this->expectExceptionMessage('Identifier type \'KRS\' is not supported');
        self::$reader->lookup('7282697380', IdentifierType::KRS);
    }

    public function testCorrectNipByDate()
    {
        $response = self::$reader->lookupDate(
            '7282697380',
            date('Y-m-d', strtotime('-1day'))
        );
        $this->assertTrue($response->valid);
    }

    public function testIncorrectNip()
    {
        $this->expectExceptionMessage("Empty reponse");
        self::$reader->lookup('5252389922');
    }

    public function testEmptyReponse()
    {
        $this->expectException(KasReaderException::class);
        $this->expectExceptionMessage("Empty reponse");
        $response = self::$reader->lookup('5252389922');
    }

    public function testPersonAddress()
    {
        $response = self::$reader->lookup('7282697380');
        $mainAddress = $response->mainAddress;
        $this->assertNotEmpty($mainAddress);
        $this->assertNotEmpty($mainAddress->address);
        $this->assertNotEmpty($mainAddress->postalCode);
        $this->assertNotEmpty($mainAddress->city);
        $this->assertNotEmpty($mainAddress->country);
    }

    public function testOrganizationAddress()
    {
        $response = self::$reader->lookup('7252285833');
        $mainAddress = $response->mainAddress;
        $this->assertNotEmpty($mainAddress);
        $this->assertNotEmpty($mainAddress->address);
        $this->assertNotEmpty($mainAddress->postalCode);
        $this->assertNotEmpty($mainAddress->city);
        $this->assertNotEmpty($mainAddress->country);
    }

    public function testEmptyKas()
    {
        $response = self::$reader->lookup('7282697380');
        $krsIdentifier = array_filter(
            $response->identifiers,
            function (CompanyIdentifier $identifier) {
                return $identifier->type == IdentifierType::KRS;
            }
        );

        $this->assertEmpty($krsIdentifier);
    }
}
