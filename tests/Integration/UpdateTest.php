<?php
// New versions are found through GitHub Releases (faked) and installed from the release archive.
final class UpdateTest extends IntegrationTestCase
{
    // the version the interface offers to install, or null
    private function offered()
    {
        $boot = Env::uiOpen();
        $this->assertSame(200, $boot['status'], $boot['body']);
        return $boot['json']['status']['update'] === null ? null : $boot['json']['status']['update']['version'];
    }

    private function release($mode, $version = '9.9.9')
    {
        Env::fake(array('github' => array('mode' => $mode, 'version' => $version)));
    }

    public function testNoReleasesMeansNoUpdate()
    {
        $this->assertNull($this->offered());
        $this->assertCount(1, Env::fakeRequests('/releases/latest'));
    }

    public function testNewerReleaseIsAnnouncedAndTheAnswerIsCached()
    {
        $this->release('ok');

        $this->assertSame('9.9.9', $this->offered());
        $this->assertSame('9.9.9', $this->offered());
        $this->assertStringContainsString('пункт <b>один</b>', Env::uiOpen()['json']['status']['update']['notes'], 'notes are sent as text; the page shows them as text');
        $this->assertCount(1, Env::fakeRequests('/releases/latest'), 'GitHub is asked once, then the cache is used');
    }

    public function testSameOrOlderReleaseIsNotAnnounced()
    {
        $this->release('ok', AppState::version());
        $this->assertNull($this->offered());
    }

    public function testGithubFailureDoesNotBreakThePage()
    {
        $this->release('500');

        $this->assertNull($this->offered());
    }

    public function testUpdateFromTheWebInterface()
    {
        $this->release('ok');
        $config = file_get_contents('/app/config.php');
        AppState::setSettings(array('userAgent' => 'kept across the update'));
        // leftovers of the previous version and the user's own files
        @mkdir('/app/class');
        @mkdir('/app/trackers');
        file_put_contents('/app/class/Old.class.php', '<?php');
        file_put_contents('/app/trackers/old.engine.php', '<?php');
        file_put_contents('/app/src/Tracker/RemovedTracker.php', '<?php');
        file_put_contents('/app/scripts/mine.sh', '#!/bin/sh');
        file_put_contents('/app/torrents/keep.torrent', 'x');

        $page = Env::ui('update.install');

        $this->assertTrue($page['json']['ok'], $page['body']);
        $this->assertStringContainsString('Обновление до версии: 9.9.9 выполнено успешно.', $page['json']['output']);
        $this->assertSame('9.9.9', AppState::version());
        $this->assertFileExists('/app/src/AddedByRelease.php', 'files from the archive are installed');
        $this->assertSame($config, file_get_contents('/app/config.php'), 'config.php is kept');
        $this->assertSame('kept across the update', AppState::setting('userAgent'), 'data is kept');
        $this->assertDirectoryDoesNotExist('/app/class', 'folders of the previous version are removed');
        $this->assertDirectoryDoesNotExist('/app/trackers');
        $this->assertFileDoesNotExist('/app/src/Tracker/RemovedTracker.php', 'files that left the program are removed');
        $this->assertFileExists('/app/scripts/mine.sh', 'user scripts are kept');
        $this->assertFileExists('/app/torrents/keep.torrent', 'torrent files are kept');
        unlink('/app/scripts/mine.sh');
        unlink('/app/torrents/keep.torrent');
        $this->assertFileDoesNotExist('/app/update.zip');
        $this->assertDirectoryDoesNotExist('/app/tmp_update');
        $this->assertSame('updated_9.9.9', AppState::news()[0]['id']);
        $this->assertNoWarnings();
        $this->assertNull($this->offered());
    }

    public function testEngineAnnouncesReleaseOnceWhenAutoUpdateIsOff()
    {
        $this->release('ok');

        Env::runEngine();
        Env::runEngine();

        $news = AppState::news();
        $this->assertCount(1, $news);
        $this->assertSame('update_9.9.9', $news[0]['id']);
        $this->assertStringContainsString('пункт два', $news[0]['text']);
        $this->assertSame(json_decode(file_get_contents('/src/version.txt'), true)['system'], AppState::version(), 'nothing is installed');
    }

    public function testEngineInstallsReleaseWhenAutoUpdateIsOn()
    {
        $this->release('ok');
        AppState::setSettings(array('autoUpdate' => '1'));

        $output = Env::runEngine();

        $this->assertStringContainsString('Обновление до версии: 9.9.9 выполнено успешно.', $output);
        $this->assertSame('9.9.9', AppState::version());
        $this->assertNoWarnings();
    }

    public function testNothingIsInstalledWhenGithubIsDown()
    {
        // the release is known from an earlier check, then GitHub stops answering
        $this->release('ok');
        Env::uiOpen();
        $this->release('500');
        $before = AppState::version();

        $page = Env::ui('update.install');

        $this->assertSame($before, AppState::version());
        $this->assertFalse($page['json']['ok']);
    }
}
