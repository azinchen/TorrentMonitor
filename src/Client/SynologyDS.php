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

        // the API does not return the id of the created task: find it by the link, otherwise take the last one
        $last = NULL;
        $match = NULL;
        foreach ($this->tasks() as $task)
        {
            $last = $task['id'];
            if (isset($task['additional']['detail']['uri']) && $task['additional']['detail']['uri'] == $options['url'])
                $match = $task['id'];
        }
        $id = $match !== NULL ? $match : $last;
        if (empty($id))
            throw new ClientException('add_fail');
        return (string)$id;
    }

    public function remove(string $id, bool $deleteData): void
    {
        $this->signIn();
        $this->send(Request::get($this->address.'/webapi/DownloadStation/task.cgi?api=SYNO.DownloadStation.Task&version=3&method=delete&id='.rawurlencode($id).'&_sid='.rawurlencode($this->sid)));
    }

    public function info(string $id): ?array
    {
        return NULL;
    }
}
