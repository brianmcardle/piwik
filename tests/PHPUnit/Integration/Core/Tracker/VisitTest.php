<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
use Piwik\Access;
use Piwik\IP;
use Piwik\Plugins\SitesManager\API;
use Piwik\Tracker\Request;
use Piwik\Tracker\VisitExcluded;

/**
 * Class Core_Tracker_VisitTest
 *
 * @group Core
 */
class Core_Tracker_VisitTest extends DatabaseTestCase
{
    public function setUp()
    {
        parent::setUp();

        // setup the access layer
        $pseudoMockAccess = new FakeAccess;
        FakeAccess::$superUser = true;
        Access::setSingletonInstance($pseudoMockAccess);

        \Piwik\Plugin\Manager::getInstance()->loadPlugins(array('SitesManager'));
    }

    /**
     * Dataprovider
     */
    public function getExcludedIpTestData()
    {
        return array(
            array('12.12.12.12', array(
                '12.12.12.12'     => true,
                '12.12.12.11'     => false,
                '12.12.12.13'     => false,
                '0.0.0.0'         => false,
                '255.255.255.255' => false
            )),
            array('12.12.12.12/32', array(
                '12.12.12.12'     => true,
                '12.12.12.11'     => false,
                '12.12.12.13'     => false,
                '0.0.0.0'         => false,
                '255.255.255.255' => false
            )),
            array('12.12.12.*', array(
                '12.12.12.0'      => true,
                '12.12.12.255'    => true,
                '12.12.12.12'     => true,
                '12.12.11.255'    => false,
                '12.12.13.0'      => false,
                '0.0.0.0'         => false,
                '255.255.255.255' => false,
            )),
            array('12.12.12.0/24', array(
                '12.12.12.0'      => true,
                '12.12.12.255'    => true,
                '12.12.12.12'     => true,
                '12.12.11.255'    => false,
                '12.12.13.0'      => false,
                '0.0.0.0'         => false,
                '255.255.255.255' => false,
            )),
            // add some ipv6 addresses!
        );
    }

    /**
     * @group Core
     * @dataProvider getExcludedIpTestData
     */
    public function testIsVisitorIpExcluded($excludedIp, $tests)
    {
        $idsite = API::getInstance()->addSite("name", "http://piwik.net/", $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, $excludedIp);

        $request = new Request(array('idsite' => $idsite));

        // test that IPs within the range, or the given IP, are excluded
        foreach ($tests as $ip => $expected) {
            $testIpIsExcluded = IP::P2N($ip);

            $excluded = new VisitExcluded_public($request, $testIpIsExcluded);
            $this->assertSame($expected, $excluded->public_isVisitorIpExcluded($testIpIsExcluded));
        }
    }

    /**
     * Dataprovider for testIsVisitorUserAgentExcluded.
     */
    public function getExcludedUserAgentTestData()
    {
        return array(
            array('', array(
                'whatever'        => false,
                ''                => false,
                'nlksdjfsldkjfsa' => false,
            )),
            array('mozilla', array(
                'this has mozilla in it' => true,
                'this doesn\'t'          => false,
                'partial presence: mozi' => false,
            )),
            array('cHrOmE,notinthere,&^%', array(
                'chrome is here' => true,
                'CHROME is here' => true,
                '12&^%345'       => true,
                'sfasdf'         => false,
            )),
        );
    }

    /**
     * @group Core
     * @dataProvider getExcludedUserAgentTestData
     */
    public function testIsVisitorUserAgentExcluded($excludedUserAgent, $tests)
    {
        API::getInstance()->setSiteSpecificUserAgentExcludeEnabled(true);

        $idsite = API::getInstance()->addSite("name", "http://piwik.net/", $ecommerce = 0,
            $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null, $excludedIp = null,
            $excludedQueryParameters = null, $timezone = null, $currency = null, $group = null, $startDate = null,
            $excludedUserAgent);

        $request = new Request(array('idsite' => $idsite));
        
        // test that user agents that contain excluded user agent strings are excluded
        foreach ($tests as $ua => $expected) {
            $excluded = new VisitExcluded_public($request, $ip = false, $ua);
            
            $this->assertSame($expected, $excluded->public_isUserAgentExcluded(), "Result if isUserAgentExcluded('$ua') was not " . ($expected ? 'true' : 'false') . ".");
        }
    }

    /**
     * @group Core
     * @group referrerIsKnownSpam
     */
    public function testIsVisitor_referrerIsKnownSpam()
    {
        $knownSpammers = array(
            'http://semalt.com' => true,
            'http://semalt.com/random/sub/page' => true,
            'http://semalt.com/out/of/here?mate' => true,
            'http://valid.domain/' => false,
            'http://valid.domain/page' => false,
        );
        API::getInstance()->setSiteSpecificUserAgentExcludeEnabled(true);

        $idsite = API::getInstance()->addSite("name", "http://piwik.net/");


        // test that user agents that contain excluded user agent strings are excluded
        foreach ($knownSpammers as $spamUrl => $expectedIsReferrerSpam) {
            $spamUrl = urlencode($spamUrl);
            $request = new Request(array(
                'idsite' => $idsite,
                'urlref' => $spamUrl
            ));
            $excluded = new VisitExcluded_public($request);

            $this->assertSame($expectedIsReferrerSpam, $excluded->public_isReferrerSpamExcluded(), $spamUrl);
        }
    }
    /**
     * @group Core
     * @group IpIsKnownBot
     */
    public function testIsVisitor_ipIsKnownBot()
    {
        $isIpBot = array(
            // Source: http://forum.piwik.org/read.php?3,108926
            '66.249.85.36' => true,
            '66.249.91.150' => true,
            '64.233.172.1' => true,

            // ddos bot
            '1.202.218.8' => true,

            // Not bots
            '66.248.91.150' => false,
            '66.250.91.150' => false,
        );

        $idsite = API::getInstance()->addSite("name", "http://piwik.net/");
        $request = new Request(array('idsite' => $idsite, 'bots' => 0));

        foreach ($isIpBot as $ip => $isBot) {
            $excluded = new VisitExcluded_public($request, IP::P2N($ip));

            $this->assertSame($isBot, $excluded->public_isNonHumanBot(), $ip);
        }
    }
}

class VisitExcluded_public extends VisitExcluded
{
    public function public_isVisitorIpExcluded($ip)
    {
        return $this->isVisitorIpExcluded($ip);
    }

    public function public_isUserAgentExcluded()
    {
        return $this->isUserAgentExcluded();
    }
    public function public_isReferrerSpamExcluded()
    {
        return $this->isReferrerSpamExcluded();
    }
    public function public_isNonHumanBot()
    {
        return $this->isNonHumanBot();
    }
}
