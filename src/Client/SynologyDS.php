<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\Http\Request;

// Synology Download Station. Takes a link to the torrent file on the TorrentMonitor server;
// the torrent id is the task id. Deleting downloaded files is not supported.
final class SynologyDS extends HttpClientBase
{
    private string $sid = '';

    private function signIn(): void
    {
        if ($this->sid !== '')
            return;
        $response = $this->send(Request::get($this->address.'/webapi/auth.cgi?'.http_build_query(array(
            'api' => 'SYNO.API.Auth', 'version' => 7, 'method' => 'login', 'account' => $this->login, 'passwd' => $this->password,
            'session' => 'DownloadStation', 'format' => 'sid',
        ))));
        $data = $response->json();
        if (empty($data['success']) || empty($data['data']['sid']))
            throw new ClientException('log_passwd');
        $this->sid = $data['data']['sid'];
    }

    private function tasks(): array
    {
        $response = $this->send(Request::get($this->address.'/webapi/DownloadStation/task.cgi?api=SYNO.DownloadStation.Task&version=1&method=list&additional=detail&_sid='.rawurlencode($this->sid)));
        $data = $response->json();
        return isset($data['data']['tasks']) && is_array($data['data']['tasks']) ? $data['data']['tasks'] : array();
    }

    public function add(string $file, array $options): string
    {
        if (empty($options['url']))
            throw new ClientException('no_response');
        $this->signIn();
        foreach ($this->tasks() as $task)
            if (isset($task['additional']['detail']['uri']) && $task['additional']['detail']['uri'] == $options['url'])
                throw new ClientException('duplicate_torrent');

        $response = $this->send(Request::post($this->address.'/webapi/DownloadStation/task.cgi', array(
            'api' => 'SYNO.DownloadStation.Task', 'version' => '3', 'method' => 'create', 'session' => 'DownloadStation',
            'uri' => $options['url'], 'destination' => isset($options['path']) ? $options['path'] : '', '_sid' => $this->sid,
        ))->follow());
        if (preg_match('/\"code\":403/i', $response->body))
            throw new ClientException('destination_does_not_exist');

        if ( ! preg_match('/\"success\":\s*true/i', $response->body))
            throw new ClientException('add_fail');

        // the API does not return the id of the created task: find it by the link. Never guess:
        // a wrong id would make the next update delete somebody else's download. Without an id
        // the old task simply stays in Download Station.
        $match = '';
        foreach ($this->tasks() as $task)
            if (isset($task['additional']['detail']['uri']) && $task['additional']['detail']['uri'] == $options['url'])
                $match = (string)$task['id'];
        return $match;
    }

    public function remove(string $id, bool $deleteData): void
    {
        $this->signIn();
        $this->send(Request::get($this->address.'/webapi/DownloadStation/task.cgi?api=SYNO.DownloadStation.Task&version=3&method=delete&id='.rawurlencode($id).'&_sid='.rawurlencode($this->sid)));
    }

    // Download Station cannot be asked about a torrent by its hash; signing in at least
    // tells a wrong address or password apart from a working connection
    public function info(string $id): ?array
    {
        $this->signIn();
        return NULL;
    }
}
