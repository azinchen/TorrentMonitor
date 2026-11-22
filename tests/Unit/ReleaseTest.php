<?php
use PHPUnit\Framework\TestCase;

require_once TM_ROOT.'/class/Config.class.php';
require_once TM_ROOT.'/class/System.class.php';

final class ReleaseTest extends TestCase
{
    public function testTagIsTurnedIntoVersion()
    {
        $this->assertSame('3.4.0', Release::normalizeVersion('v3.4.0'));
        $this->assertSame('3.4.0', Release::normalizeVersion(' V3.4.0 '));
        $this->assertSame('4.0.0-beta.1', Release::normalizeVersion('4.0.0-beta.1'));
    }

    public function testCurrentVersionComesFromVersionFile()
    {
        $file = json_decode(file_get_contents(TM_ROOT.'/version.txt'), true);
        $this->assertSame($file['system'], Release::currentVersion());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Release::currentVersion());
    }

    public function testNewerReleaseIsDetectedNumerically()
    {
        $current = Release::currentVersion();
        list($major, $minor) = explode('.', $current);

        $this->assertTrue(Release::isNewer(array('version' => ($major + 1).'.0.0')));
        // 3.10.0 is newer than 3.9.0, although it sorts lower as a string
        $this->assertTrue(Release::isNewer(array('version' => $major.'.'.($minor + 10).'.0')));
        $this->assertFalse(Release::isNewer(array('version' => $current)));
        $this->assertFalse(Release::isNewer(array('version' => '0.9.9')));
    }

    public function testMissingReleaseIsNotNewer()
    {
        $this->assertFalse(Release::isNewer(null));
        $this->assertFalse(Release::isNewer(array('version' => '')));
    }

    public function testLinksPointToTheReleaseRepository()
    {
        $this->assertSame('https://github.com/'.Release::REPO.'/releases', Release::pageUrl());
        $this->assertSame('https://github.com/'.Release::REPO.'/wiki', Release::wikiUrl());
    }
}
