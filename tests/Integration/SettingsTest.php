<?php
// Settings saved through the web interface.
final class SettingsTest extends IntegrationTestCase
{
    public function testActionsNeedTheCsrfToken()
    {
        $response = Http::post(Env::APP.'/action.php', array('action' => 'update_torrent'));
        $this->assertTrue($response['json']['error']);
        $this->assertSame('0', AppState::setting('useTorrent'));
    }

    public function testTorrentClientSettingsAreSaved()
    {
        $response = Env::uiAction(array(
            'action' => 'update_torrent', 'useTorrent' => 'true', 'torrentClient' => 'qBittorrent',
            'torrentAddress' => Env::QBT.'/', 'torrentLogin' => 'someone', 'torrentPassword' => 'pa$$ word',
            'pathToDownload' => '/downloads/x', 'deleteDistribution' => 'false', 'deleteOldFiles' => 'true',
            'deleteFilesOnRemove' => 'true', 'qbitCategory' => 'tv',
        ));

        $this->assertFalse($response['json']['error'], $response['body']);
        $this->assertSame('1', AppState::setting('useTorrent'));
        $this->assertSame(Env::QBT, AppState::setting('torrentAddress'), 'trailing slash is removed');
        $this->assertSame('/downloads/x/', AppState::setting('pathToDownload'), 'trailing slash is added');
        $this->assertSame('1', AppState::setting('deleteOldFiles'));
        $this->assertSame('0', AppState::setting('deleteDistribution'));
        $this->assertSame('1', AppState::setting('deleteFilesOnRemove'));
        $this->assertSame('tv', AppState::setting('qbitCategory'));
    }

    public function testDeleteDataSettingIsOffUntilEnabled()
    {
        $this->assertSame('0', (string)AppState::setting('deleteFilesOnRemove'));
        $page = Env::uiGet('/include/settings.php');
        $this->assertStringContainsString('Удалять скачанные файлы при удалении раздачи из torrent-клиента', $page['body']);
    }

    public function testSettingsPageDoesNotBreakOnQuotesInValues()
    {
        AppState::setSettings(array('userAgent' => 'Agent "quoted" \'single\' <tag>'));
        $page = Env::uiGet('/include/settings.php');
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('Fatal error', $page['body']);
    }

    public function testPasswordProtectsTheInterface()
    {
        AppState::setSettings(array('auth' => '1'));

        $anonymous = Http::get(Env::APP.'/include/settings.php');

        $this->assertStringNotContainsString('torrentPassword', $anonymous['body']);
    }
}
