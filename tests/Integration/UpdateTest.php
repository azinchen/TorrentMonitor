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
        $this->assertCount(1, Env::fakeRequests('/releases'));
    }

    public function testNewerReleaseIsAnnouncedAndTheAnswerIsCached()
    {
        $this->release('ok');

        $this->assertSame('9.9.9', $this->offered());
        $this->assertSame('9.9.9', $this->offered());
        $this->assertStringContainsString('пункт <b>один</b>', Env::uiOpen()['json']['status']['update']['notes'], 'notes are sent as text; the page shows them as text');
        $this->assertCount(1, Env::fakeRequests('/releases'), 'GitHub is asked once, then the cache is used');
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
        $this->assertNoWarnings();
        $this->assertNull($this->offered());
    }

    public function testEngineAnnouncesReleaseOnceWhenAutoUpdateIsOff()
    {
        $this->release('ok');

        Env::runEngine();
        Env::runEngine();

        $this->assertTrue(AppState::state()['updateNotified'], 'the announcement is not repeated at the next run');
        $this->assertNotNull($this->offered(), 'the interface offers the version');
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

    // The answer of GitHub is kept for six hours. A run started by hand does not wait for that.
    public function testRunStartedByHandLooksForANewVersionNow()
    {
        // "no new version" was answered and is remembered
        $this->assertNull($this->offered());
        $this->release('ok');
        AppState::setSettings(array('autoUpdate' => '1'));

        // a scheduled run trusts the remembered answer
        Env::runEngine();
        $this->assertSame(json_decode(file_get_contents('/src/version.txt'), true)['system'], AppState::version(), 'nothing is installed by the scheduled run');

        // «Проверить сейчас» asks again and installs
        $started = Env::ui('run');
        $this->assertTrue($started['json']['ok'], $started['body']);
        Env::waitFor(function () { return AppState::version() === '9.9.9'; }, 'the update to be installed by the run started by hand');
        Env::waitFor(function () { return ! Env::ui('run.log')['json']['running']; }, 'the check to finish');
        $this->assertNoWarnings();
    }

    public function testFreshOptionOfTheCommandLine()
    {
        $this->assertNull($this->offered());
        $this->release('ok');
        AppState::setSettings(array('autoUpdate' => '1'));
        $output = Env::sh('cd /app && php engine.php --fresh 2>&1');
        $this->assertStringContainsString('Обновление до версии: 9.9.9 выполнено успешно.', $output);
    }

    // ---- update channels

    private function channel($channel)
    {
        $saved = Env::ui('settings.save', array('autoUpdate' => true, 'updateChannel' => $channel));
        $this->assertTrue($saved['json']['ok'], $saved['body']);
        return $saved['json'];
    }

    private function commit()
    {
        $version = json_decode(file_get_contents('/app/version.txt'), true);
        return isset($version['commit']) ? $version['commit'] : '';
    }

    public function testStableChannelIgnoresPreReleases()
    {
        Env::fake(array('github' => array('mode' => 'ok', 'version' => AppState::version(), 'beta' => '9.9.9-beta1')));
        $this->channel('stable');
        $this->assertNull($this->offered());
    }

    public function testBetaChannelTakesWhicheverIsNewer()
    {
        // a pre-release above the regular release
        Env::fake(array('github' => array('mode' => 'ok', 'version' => '9.9.8', 'beta' => '9.9.9-beta1')));
        $this->channel('beta');
        $this->assertSame('9.9.9-beta1', $this->offered());

        // the regular release above the pre-release
        Env::fake(array('github' => array('mode' => 'ok', 'version' => '9.9.9', 'beta' => '9.9.9-beta1')));
        Env::ui('update.check');
        $this->assertSame('9.9.9', $this->offered());

        $output = Env::runEngine();
        $this->assertStringContainsString('Обновление до версии: 9.9.9 выполнено успешно.', $output);
        $this->assertSame('9.9.9', AppState::version());
    }

    public function testChannelIsOnlyUsedWithAutomaticUpdates()
    {
        Env::fake(array('github' => array('mode' => 'ok', 'version' => AppState::version(), 'beta' => '9.9.9-beta1')));
        $this->channel('beta');
        $this->assertSame('9.9.9-beta1', $this->offered());

        // switching automatic updates off goes back to regular releases and does not go down a version
        $this->assertTrue(Env::ui('settings.save', array('autoUpdate' => false))['json']['ok']);
        $this->assertNull($this->offered());
        $this->assertFalse(AppState::state()['channelChanged']);
    }

    public function testDevChannelInstallsTheNewestCommitAndThenWaitsForTheNextOne()
    {
        $first = str_repeat('a1', 20);
        Env::fake(array('github' => array('mode' => 'ok', 'version' => AppState::version(), 'commit' => $first, 'base' => '9.9.9-beta2')));
        $json = $this->channel('dev');
        $this->assertStringContainsString('будет установлена версия dev a1a1a1a', $json['message']);
        $this->assertSame('dev a1a1a1a', $this->offered());

        $output = Env::runEngine();
        $this->assertStringContainsString('выполнено успешно', $output);
        $this->assertSame('9.9.9-beta2', AppState::version(), 'the number is the one inside the archive');
        $this->assertSame($first, $this->commit());
        $this->assertSame('9.9.9-beta2 (dev a1a1a1a)', Env::uiOpen()['json']['status']['version']);
        $this->assertFileExists('/app/src/AddedByRelease.php', 'the archive of a commit has its files one folder down');
        $this->assertNull($this->offered(), 'the same commit is not installed again');
        $this->assertNoWarnings();

        // the next commit: its notes list what changed since the installed one
        $second = str_repeat('b2', 20);
        Env::fake(array('github' => array('commit' => $second)));
        Env::ui('update.check');
        $update = Env::uiOpen()['json']['status']['update'];
        $this->assertSame('dev b2b2b2b', $update['version']);
        $this->assertStringContainsString('Первое изменение после a1a1a1a', $update['notes']);
        $this->assertStringContainsString('- Последнее изменение', $update['notes']);
        $this->assertStringNotContainsString('подробности', $update['notes'], 'only the first line of a commit');
    }

    public function testChangingTheChannelInstallsItsVersionEvenIfLower()
    {
        // installed from the dev channel, on top of 9.9.9-beta2
        $sha = str_repeat('a1', 20);
        Env::fake(array('github' => array('mode' => 'ok', 'version' => '9.9.8', 'beta' => '9.9.9-beta1', 'commit' => $sha, 'base' => '9.9.9-beta2')));
        $this->channel('dev');
        Env::runEngine();
        $this->assertSame('9.9.9-beta2', AppState::version());

        // dev -> beta: the newest beta is lower and is installed
        $json = $this->channel('beta');
        $this->assertStringContainsString('будет установлена версия 9.9.9-beta1, она не выше текущей 9.9.9-beta2 (dev a1a1a1a)', $json['message']);
        $update = Env::uiOpen()['json']['status']['update'];
        $this->assertSame(array('9.9.9-beta1', true), array($update['version'], $update['downgrade']));
        $output = Env::runEngine();
        $this->assertStringContainsString('выполнено успешно', $output);
        $this->assertSame('9.9.9-beta1', AppState::version());
        $this->assertSame('', $this->commit(), 'a release has no commit recorded');
        $this->assertFalse(AppState::state()['channelChanged'], 'the change of the channel is complete');

        // beta -> stable: lower again
        $this->channel('stable');
        Env::runEngine();
        $this->assertSame('9.9.8', AppState::version());
        $this->assertNoWarnings();

        // without a change of the channel a lower version is never installed
        Env::fake(array('github' => array('version' => '9.9.7')));
        Env::ui('update.check');
        $this->assertNull($this->offered());
        Env::runEngine();
        $this->assertSame('9.9.8', AppState::version());
    }

    public function testChannelChangeDoesNotGoBelowTheFirstVersionWithPortableData()
    {
        Env::fake(array('github' => array('mode' => 'ok', 'version' => '3.9.0', 'beta' => '9.9.9-beta1')));
        $this->channel('beta');
        Env::runEngine();
        $this->assertSame('9.9.9-beta1', AppState::version());

        $this->channel('stable');
        $this->assertNull($this->offered(), 'version 3 is not offered');
        Env::runEngine();
        $this->assertSame('9.9.9-beta1', AppState::version());
    }

    public function testUnknownChannelIsRefused()
    {
        $this->assertFalse(Env::ui('settings.save', array('autoUpdate' => true, 'updateChannel' => 'nightly'))['json']['ok']);
    }

    // ---- release descriptions

    public function testNotesOfSkippedVersionsAreShownToo()
    {
        Env::fake(array('github' => array('mode' => 'ok', 'version' => '9.9.9', 'notes' => "Итоговая версия.\n\n### Новое\n\n- пункт",
            'older' => array('9.9.9-beta2' => 'Вторая бета.', '9.9.9-beta1' => 'Первая бета.', '0.0.1' => 'Старая версия.'))));
        $this->channel('beta');
        Env::ui('update.check');
        $notes = Env::uiOpen()['json']['status']['update']['notes'];
        $this->assertStringStartsWith("## 9.9.9\n\nИтоговая версия.", $notes);
        $this->assertStringContainsString("## 9.9.9-beta2\n\nВторая бета.\n\n## 9.9.9-beta1\n\nПервая бета.", $notes, 'newest first');
        $this->assertStringNotContainsString('Старая версия', $notes, 'versions not above the installed one are left out');

        // the regular releases only: the betas between are not part of that channel
        $this->channel('stable');
        Env::ui('update.check');
        $this->assertSame("Итоговая версия.\n\n### Новое\n\n- пункт", Env::uiOpen()['json']['status']['update']['notes']);
    }
}
