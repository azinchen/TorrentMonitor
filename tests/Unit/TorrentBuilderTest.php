<?php
use PHPUnit\Framework\TestCase;

// The builder is test infrastructure; these checks make sure the hashes other tests rely on are stable.
final class TorrentBuilderTest extends TestCase
{
    public function testBencode()
    {
        $this->assertSame('d1:ai1e1:bl1:x1:yee', TorrentBuilder::bencode(array('b' => array('x', 'y'), 'a' => 1)));
    }

    public function testHashDependsOnTopicAndRevision()
    {
        $this->assertSame(TorrentBuilder::infoHash(5, 1), TorrentBuilder::infoHash(5, 1));
        $this->assertNotSame(TorrentBuilder::infoHash(5, 1), TorrentBuilder::infoHash(5, 2));
        $this->assertNotSame(TorrentBuilder::infoHash(5, 1), TorrentBuilder::infoHash(6, 1));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', TorrentBuilder::infoHash(5, 1));
    }

    public function testPayloadMatchesDeclaredLength()
    {
        $this->assertSame(40000, strlen(TorrentBuilder::payload(5, 1)));
        $this->assertStringContainsString('6:lengthi40000e', TorrentBuilder::torrent(5, 1, 'c'));
    }
}
