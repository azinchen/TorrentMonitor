<?php
use PHPUnit\Framework\TestCase;

final class ScrubberTest extends TestCase
{
    private $secrets = array('RUTRACKER_LOGIN' => 'Вася Pupkin', 'RUTRACKER_PASSWORD' => 'p@ss&word', 'EMPTY' => '', 'TOPIC' => '12');

    public function testSecretValuesAreReplacedInEveryEncoding()
    {
        $page = 'Привет, Вася Pupkin! <a href="?u='.urlencode('Вася Pupkin').'">'.htmlspecialchars('p@ss&word').'</a> '
            .iconv('utf-8', 'windows-1251', 'Вася Pupkin');

        $clean = Scrubber::scrub($page, $this->secrets);

        $this->assertStringNotContainsString('Pupkin', $clean);
        $this->assertStringNotContainsString('p@ss', $clean);
        $this->assertSame(4, substr_count($clean, '{{RUTRACKER_LOGIN}}') + substr_count($clean, '{{RUTRACKER_PASSWORD}}'));
        $this->assertSame(array(), Scrubber::leaks($clean, $this->secrets));
    }

    public function testShortAndEmptyValuesAreIgnored()
    {
        // replacing "12" everywhere would wreck the page
        $this->assertSame('topic 12 of 120', Scrubber::scrub('topic 12 of 120', $this->secrets));
    }

    public function testAccountKeysAreRemoved()
    {
        $page = 'dl.php?id=5&passkey=0123456789abcdef0123456789abcdef '
            .'<input name="uk" value="x"> uk=AbCdEf123456 '
            .'http://bt.tracker.example/ann?uk=AbCdEf123456&x=1 '
            .'Set-Cookie: bb_session=0-123456-AbCdEfGh; path=/ '
            .'uid=987654; pass=0a1b2c3d4e5f '
            .'mail me@example.org from 203.0.113.77';

        $clean = Scrubber::scrub($page);

        foreach (array('0123456789abcdef', 'AbCdEf123456', '0-123456-AbCdEfGh', '987654', '0a1b2c3d4e5f', 'me@example.org', '203.0.113.77') as $private)
            $this->assertStringNotContainsString($private, $clean);
        $this->assertStringContainsString('dl.php?id=5&passkey=SCRUBBED', $clean);
        $this->assertStringContainsString('bb_session=SCRUBBED', $clean);
    }

    public function testPageStructureIsKept()
    {
        $page = "<td style=\"width: 70%; padding: 5px;\">\n\t\t<ul class=\"inlined middot-separated\">\n\t\t\t<li>21-Ноя-26 10:00</li>";
        $this->assertSame($page, Scrubber::scrub($page, $this->secrets));
    }

    public function testLeaksAreDetected()
    {
        $this->assertSame(array('RUTRACKER_PASSWORD'), Scrubber::leaks('x p@ss&word y', $this->secrets));
    }
}
