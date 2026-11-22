<?php
use PHPUnit\Framework\TestCase;

require_once TM_ROOT.'/class/Config.class.php';
require_once TM_ROOT.'/class/System.class.php';

final class SystemHelpersTest extends TestCase
{
    /** @dataProvider cloudflarePages */
    public function testCloudflareChallengeIsRecognised($body, $expected)
    {
        $this->assertSame($expected, Sys::isCloudflarePage($body));
    }

    public function cloudflarePages()
    {
        return array(
            'challenge title'        => array('<title>Just a moment...</title>', true),
            'old verification class' => array('<div class="cf-browser-verification">', true),
            'checking browser'       => array('Checking your browser before accessing. Cloudflare', true),
            'ddos text'              => array('DDoS protection by CLOUDFLARE', true),
            'mentions cloudflare'    => array('<script src="https://cdnjs.cloudflare.com/x.js"></script>', false),
            'normal page'            => array('<title>RuTracker.org</title>', false),
            'empty'                  => array('', false),
        );
    }

    public function testMonthNamesAreConvertedToNumbers()
    {
        $this->assertSame('21 11 2026', Sys::dateStringToNum('21 ноября 2026'));
        $this->assertSame('21-11-26 10:00', Sys::dateStringToNum('21-Ноя-26 10:00'));
        $this->assertSame('01 05 2026', Sys::dateStringToNum('01 мая 2026'));
        $this->assertSame('3 08 2026', Sys::dateStringToNum('3 Aug 2026'));
    }

    public function testMonthNumbersAreConvertedToNames()
    {
        $this->assertSame('Ноя', Sys::dateNumToString('11'));
        $this->assertSame('Янв', Sys::dateNumToString('01'));
        $this->assertSame('Дек', Sys::dateNumToString('12'));
    }

    public function testFormBooleans()
    {
        $this->assertSame(1, Sys::strBoolToInt('true'));
        $this->assertSame(1, Sys::strBoolToInt(1));
        $this->assertSame(1, Sys::strBoolToInt('1'));
        $this->assertSame(0, Sys::strBoolToInt('false'));
        $this->assertSame(0, Sys::strBoolToInt('0'));
        $this->assertSame(0, Sys::strBoolToInt(''));
    }

    public function testTorrentFileCheck()
    {
        $torrent = TorrentBuilder::torrent(1, 1, 'https://rutracker.org/forum/viewtopic.php?t=1');
        $this->assertTrue(Sys::checkTorrentFile($torrent));
        $this->assertFalse(Sys::checkTorrentFile('<html>login page, long enough to pass the length check '.str_repeat('x', 200).'</html>'));
        $this->assertFalse(Sys::checkTorrentFile('d8:announce0:e'));
        $this->assertFalse(Sys::checkTorrentFile(''));
    }

    public function testByteFormatting()
    {
        $this->assertSame('0 Б', Sys::formatBytes(0));
        $this->assertSame('512 Б', Sys::formatBytes(512));
        $this->assertSame('1.5 КБ', Sys::formatBytes(1536));
        $this->assertSame('2 ГБ', Sys::formatBytes(2 * 1024 ** 3));
    }

    public function testTrailingSlashIsRemovedFromClientAddress()
    {
        $this->assertSame('http://127.0.0.1:9091', Sys::checkUrl('http://127.0.0.1:9091/'));
        $this->assertSame('http://127.0.0.1:9091', Sys::checkUrl('http://127.0.0.1:9091'));
    }
}
