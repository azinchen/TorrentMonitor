<?php
use PHPUnit\Framework\TestCase;

require_once TM_ROOT.'/class/Url.class.php';

final class UrlTest extends TestCase
{
    /** @dataProvider topicLinks */
    public function testTopicLink($tracker, $topic, $expected)
    {
        $this->assertSame($expected, Url::href($tracker, $topic));
    }

    public function topicLinks()
    {
        return array(
            array('rutracker.org', '123', 'https://rutracker.org/forum/viewtopic.php?t=123'),
            array('nnmclub.to', '55', 'https://nnmclub.to/forum/viewtopic.php?t=55'),
            array('pornolab.net', '7', 'http://pornolab.net/forum/viewtopic.php?t=7'),
            array('kinozal.me', '99', 'http://kinozal.me/details.php?id=99'),
            array('kinozal.tv', '99', 'http://kinozal.tv/details.php?id=99'),
            array('kinozal.guru', '99', 'http://kinozal.guru/details.php?id=99'),
            array('rutor.is', '4321', 'http://rutor.is/torrent/4321'),
            array('booktracker.org', '8', 'http://booktracker.org/viewtopic.php?t=8'),
            array('anidub.com', 'anime/1.html', 'https://tr.anidub.com/anime/1.html'),
            array('baibako.tv_forum', '12', 'http://baibako.tv/details.php?id=12'),
            array('unknown.example', '1', ''),
        );
    }

    public function testTopicNameIsEscapedInLink()
    {
        $data = Url::create('rutracker.org', 'A <b> & "C"', '10');
        $this->assertStringContainsString('A &lt;b&gt; &amp; &quot;C&quot;', $data['url']);
        $this->assertStringNotContainsString('<b>', $data['url']);
        $this->assertFalse($data['quality']);
    }

    public function testStoredHtmlEntityIsNotEscapedTwice()
    {
        $data = Url::create('rutracker.org', 'Tom &amp; Jerry', '10');
        $this->assertSame('Tom & Jerry', $data['name']);
        $this->assertStringContainsString('>Tom &amp; Jerry<', $data['url']);
    }

    /** @dataProvider qualities */
    public function testSeriesQuality($tracker, $hd, $expected)
    {
        $this->assertSame($expected, Url::create($tracker, 'House', false, $hd)['quality']);
    }

    public function qualities()
    {
        return array(
            // lostfilm numbers its qualities the other way round
            array('lostfilm.tv', 0, 'sd'), array('lostfilm.tv', 1, '1080'), array('lostfilm.tv', 2, '720'),
            array('lostfilm-mirror', 1, '1080'),
            array('newstudio.tv', 0, 'sd'), array('newstudio.tv', 1, '720'), array('newstudio.tv', 2, '1080'),
            array('baibako.tv', 2, '1080'),
        );
    }
}
