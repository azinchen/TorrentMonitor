<?php
// New versions are found through GitHub Releases (faked) and installed from the release archive.
final class UpdateTest extends IntegrationTestCase
{
    const BANNER = 'Доступна новая версия TorrentMonitor';

    private function release($mode, $version = '9.9.9')
    {
        Env::fake(array('github' => array('mode' => $mode, 'version' => $version)));
    }

    public function testNoReleasesMeansNoUpdate()
    {
        $page = Env::uiOpen();

        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString(self::BANNER, $page['body']);
        $this->assertCount(1, Env::fakeRequests('/releases/latest'));
    }

    public function testNewerReleaseIsAnnouncedAndTheAnswerIsCached()
    {
        $this->release('ok');

        $this->assertStringContainsString(self::BANNER, Env::uiOpen()['body']);
        $this->assertStringContainsString(self::BANNER, Env::uiOpen()['body']);
        $this->assertCount(1, Env::fakeRequests('/releases/latest'), 'GitHub is asked once, then the cache is used');
    }

    public function testSameOrOlderReleaseIsNotAnnounced()
    {
        $this->release('ok', AppState::version());
        $this->assertStringNotContainsString(self::BANNER, Env::uiOpen()['body']);
    }

    public function testGithubFailureDoesNotBreakThePage()
    {
        $this->release('500');

        $page = Env::uiOpen();

        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString(self::BANNER, $page['body']);
    }

    public function testUpdateFromTheWebInterface()
    {
        $this->release('ok');
        $config = file_get_contents('/app/config.php');
        AppState::setSettings(array('userAgent' => 'kept across the update'));

        $page = Env::uiGet('/include/update.php');

        $this->assertStringContainsString('Обновление до версии: 9.9.9 выполнено успешно.', $page['body']);
        $this->assertStringContainsString('пункт &lt;b&gt;один&lt;/b&gt;', $page['body'], 'release notes are shown escaped');
        $this->assertSame('9.9.9', AppState::version());
        $this->assertFileExists('/app/src/AddedByRelease.php', 'files from the archive are installed');
        $this->assertSame($config, file_get_contents('/app/config.php'), 'config.php is kept');
        $this->assertSame('kept across the update', AppState::setting('userAgent'), 'data is kept');
        $this->assertFileDoesNotExist('/app/update.zip');
        $this->assertDirectoryDoesNotExist('/app/tmp_update');
        $this->assertSame('updated_9.9.9', AppState::news()[0]['id']);
        $this->assertNoWarnings();
        $this->assertStringNotContainsString(self::BANNER, Env::uiOpen()['body']);
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

        $page = Env::uiGet('/include/update.php');

        $this->assertSame($before, AppState::version());
        $this->assertStringNotContainsString('выполнено успешно', $page['body']);
    }
}
