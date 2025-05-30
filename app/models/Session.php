<?php

class Session_model extends Model
{
    /**
     * Summary of table
     * 
     * @var string
     */
    public $table = 'sessions';
    public $table_data = 'sessions_data';

    private $table_columns = ['clientid', 'cookies', 'origin', 'referer', 'payload', 'uri', 'user-agent', 'ip', 'time', 'archive'];
    private $table_data_columns = ['sessionid', 'dom', 'localstorage', 'sessionstorage', 'console', 'compressed'];

    /**
     * Get all sessions
     * 
     * @return array
     */
    public function getAll()
    {
        $database = Database::openConnection();
        $database->prepare("SELECT p.id, p.clientid, p.ip, p.uri, p.payload, p.time, p.origin, p.`user-agent`, last_row.requests FROM $this->table p INNER JOIN ( SELECT MAX(id) as max_id, clientid, COUNT(*) as requests FROM $this->table GROUP BY clientid ) last_row ON p.id = last_row.max_id ORDER BY p.time DESC LIMIT :li");
        $database->bindValue(':li', reportsLimit);
        $database->execute();

        $data = $database->fetchAll();

        return $data;
    }

    /**
     * Get all sessions by archive status
     * 
     * @param string $archive Archive status
     * @return array
     */
    public function getAllByArchive($archive)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT p.id, p.clientid, p.ip, p.uri, p.payload, p.time, p.origin, p.`user-agent`, last_row.requests FROM $this->table p INNER JOIN ( SELECT MAX(id) as max_id, clientid, COUNT(*) as requests FROM $this->table WHERE `archive` = :archive GROUP BY clientid ) last_row ON p.id = last_row.max_id WHERE p.`archive` = :archive2 ORDER BY p.time DESC LIMIT :li");
        $database->bindValue(':archive', $archive);
        $database->bindValue(':archive2', $archive);
        $database->bindValue(':li', reportsLimit);
        $database->execute();

        $data = $database->fetchAll();

        return $data;
    }

    /**
     * Set session value of single item by id
     * 
     * @param int $id The session id
     * @param string $column The column name
     * @param string $value The new value
     * @throws Exception
     * @return bool
     */
    public function set($id, $column, $value)
    {
        if (!in_array($column, $this->table_data_columns) && !in_array($column, $this->table_columns)) {
            throw new Exception('Invalid column name');
        }

        $table = in_array($column, $this->table_data_columns) ? $this->table_data : $this->table;
        $where = $table === $this->table_data ? 'sessionid' : 'id';
        
        if ($table === $this->table_data) {
            if ($this->getCompressStatus() === 1) {
                $value = empty($value) ? $value : base64_encode(gzdeflate($value, 9));
            }
        }

        $database = Database::openConnection();
        $database->prepare("UPDATE $table SET `$column` = :value WHERE `$where` = :id");
        $database->bindValue(':value', $value);
        $database->bindValue(':id', $id);

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return true;
    }

    /**
     * Get session by id
     * 
     * @param mixed $id The session id
     * @throws Exception
     * @return array
     */
    public function getById($id)
    {
        $session = parent::getById($id);
        $session_data = $this->getSessionData($session['id']);

        return array_merge($session, $session_data);
    }

    /**
     * Get all console data
     * 
     * @param string $clientId The client id
     * @param string $origin The origin
     * @throws Exception
     * @return string
     */
    public function getAllConsole($clientId, $origin)
    {
        $console = '';
        $sessions = $this->getAllByClientId($clientId, $origin);

        foreach ($sessions as $session) {
            $session_data = $this->getSessionData($session['id']);
            $console .= $session_data['console'];
        }

        return $console;
    }

    /**
     * Get by client id
     * 
     * @param string $clientId The client id
     * @param string $origin The origin
     * @throws Exception
     * @return array
     */
    public function getByClientId($clientId, $origin)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT * FROM $this->table WHERE `clientid` = :clientid AND `origin` = :origin ORDER BY `id` DESC LIMIT 1");
        $database->bindValue(':clientid', $clientId);
        $database->bindValue(':origin', $origin);
        $database->execute();

        if ($database->countRows() === 0) {
            throw new Exception('Session not found');
        }
        $session = $database->fetch();

        $session_data = $this->getSessionData($session['id']);

        return array_merge($session, $session_data);
    }

    /**
     * Get session by payload
     * 
     * @param string $payload The payload
     * @param string $archive Archive status
     * @throws Exception
     * @return array
     */
    public function getAllByPayload($payload, $archive = 0)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT p.id, p.clientid, p.ip, p.uri, p.payload, p.time, p.origin, p.`user-agent`, last_row.requests FROM $this->table p INNER JOIN ( SELECT MAX(id) as max_id, clientid, COUNT(*) as requests FROM $this->table WHERE payload LIKE :payload AND `archive` = :archive GROUP BY clientid ) last_row ON p.id = last_row.max_id WHERE p.payload LIKE :payload2 AND p.`archive` = :archive2 ORDER BY p.time DESC");
        $database->bindValue(':payload', $payload);
        $database->bindValue(':payload2', $payload);
        $database->bindValue(':archive', $archive);
        $database->bindValue(':archive2', $archive);

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return $database->fetchAll();
    }

    /**
     * Get all session requests by client id
     * 
     * @param string $payload The payload
     * @throws Exception
     * @return array
     */
    public function getAllByClientId($clientId, $origin)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT * FROM $this->table WHERE `clientid` = :clientid AND `origin` = :origin ORDER BY id DESC");
        $database->bindValue(':clientid', $clientId);
        $database->bindValue(':origin', $origin);

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return $database->fetchAll();
    }

    /**
     * Get request count by client id
     * 
     * @param string $clientId The client id
     * @throws Exception
     * @return int
     */
    public function getRequestCount($clientId)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT `clientid` FROM $this->table WHERE `clientid` = :clientid");
        $database->bindValue(':clientid', $clientId);
        $database->execute();

        return $database->countRows();
    }

    /**
     * Get all statictics data
     * 
     * @throws Exception
     * @return array
     */
    public function getAllStaticticsData()
    {
        $database = Database::openConnection();
        $database->prepare("SELECT `clientid`,`origin`,`time` FROM $this->table ORDER BY `id` ASC");

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return $database->fetchAll();
    }

    /**
     * Get all statictics data by payload
     * 
     * @param string $payload The payload
     * @throws Exception
     * @return array
     */
    public function getAllStaticticsDataByPayload($payload)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT `clientid`,`origin`,`time`,`payload` FROM $this->table WHERE `payload` LIKE :payload ORDER BY `id` ASC");
        $database->bindValue(':payload', $payload);

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return $database->fetchAll();
    }

    /**
     * Add session
     * 
     * @param string $clientId The client id
     * @param string $cookies The cookies
     * @param string $dom The HTML dom
     * @param string $origin The origin
     * @param string $referer The referer
     * @param string $uri The url
     * @param string $userAgent The user agent
     * @param string $ip The IP
     * @param string $localStorage The local storage
     * @param string $sessionStorage The session storage
     * @param string $payload The payload name
     * @param string $console The console log
     * @throws Exception
     * @return string
     */
    public function add($clientId, $cookies, $dom, $origin, $referer, $uri, $userAgent, $ip, $localStorage, $sessionStorage, $payload, $console)
    {
        $database = Database::openConnection();

        $database->prepare("INSERT INTO $this->table (`clientid`, `cookies`, `origin`, `referer`, `uri`, `user-agent`, `ip`, `time`, `payload`, `archive`) VALUES (:clientid, :cookies, :origin, :referer, :uri, :userAgent, :ip, :time, :payload, 0)");
        $database->bindValue(':clientid', $clientId);
        $database->bindValue(':cookies', $cookies);
        $database->bindValue(':origin', $origin);
        $database->bindValue(':referer', $referer);
        $database->bindValue(':uri', $uri);
        $database->bindValue(':userAgent', $userAgent);
        $database->bindValue(':ip', $ip);
        $database->bindValue(':time', time());
        $database->bindValue(':payload', $payload);

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }
        $sessionId = $database->lastInsertId();

        // Compress data if enabled
        $compressed = false;
        if ($this->getCompressStatus() === 1) {
            $dom = base64_encode(gzdeflate($dom, 9));
            $localStorage = $localStorage === '{}' ? '{}' : base64_encode(gzdeflate($localStorage, 9));
            $sessionStorage = $sessionStorage === '{}' ? '{}' : base64_encode(gzdeflate($sessionStorage, 9));
            $console = empty($console) ? '' : base64_encode(gzdeflate($sessionStorage, 9));
            $compressed = true;
        }

        $database->prepare("INSERT INTO $this->table_data (`sessionid`, `dom`, `localstorage`, `sessionstorage`, `console`, `compressed`) VALUES (:sessionid, :dom, :localstorage, :sessionstorage, :console, :compressed)");
        $database->bindValue(':sessionid', $sessionId);
        $database->bindValue(':dom', $dom);
        $database->bindValue(':localstorage', $localStorage);
        $database->bindValue(':sessionstorage', $sessionStorage);
        $database->bindValue(':console', $console);
        $database->bindValue('compressed', $compressed === true ? 1 : 0);
        $database->execute();

        return $sessionId;
    }

    /**
     * Delete all by client id
     * 
     * @param string $clientId The client id
     * @param string $origin The origin
     * @throws Exception
     */
    public function deleteAll($clientId, $origin)
    {
        $sessions = $this->getAllByClientId($clientId, $origin);

        foreach ($sessions as $session) {
            $database = Database::openConnection();
            $database->prepare("DELETE FROM $this->table WHERE `id` = :id");
            $database->bindValue(':id', $session['id']);
            $database->execute();

            $database->prepare("DELETE FROM $this->table_data WHERE `sessionid` = :sessionid");
            $database->bindValue(':sessionid', $session['id']);
            $database->execute();
        }
    }

    /**
     * Archive all sessions by client id
     * 
     * @param string $clientId The client id
     * @param string $origin The origin
     * @throws Exception
     * @return bool
     */
    public function archiveByClientId($clientId, $origin)
    {
        $session = $this->getByClientId($clientId, $origin);

        if (empty($session)) {
            throw new Exception('Session not found');
        }

        // Get current archive status from the first session
        $currentArchive = $session['archive'] ?? '0';
        $newArchive = $currentArchive == '0' ? '1' : '0';

        $database = Database::openConnection();
        $database->prepare("UPDATE $this->table SET `archive` = :archive WHERE `clientid` = :clientid AND `origin` = :origin");
        $database->bindValue(':archive', $newArchive);
        $database->bindValue(':clientid', $clientId);
        $database->bindValue(':origin', $origin);

        if (!$database->execute()) {
            throw new Exception('Something unexpected went wrong');
        }

        return true;
    }

    /**
     * Get big data from session by id
     * 
     * @param int $id The session id
     * @return array
     */
    private function getSessionData($id)
    {
        $database = Database::openConnection();
        $database->prepare("SELECT `dom`,`localstorage`,`sessionstorage`,`console`,`compressed` FROM $this->table_data WHERE `sessionid` = :sessionid LIMIT 1");
        $database->bindValue(':sessionid', $id);
        $database->execute();

        if ($database->countRows() === 1) {
            $session_data = $database->fetch();
        }

        // Decompress if compressed
        if($session_data['compressed'] ?? 0 == 1) {
            $session_data['dom'] = gzinflate(base64_decode($session_data['dom'])) ?? '';
            $session_data['localstorage'] = $session_data['localstorage'] === '{}' ? '{}' : gzinflate(base64_decode($session_data['localstorage'])) ?? '';
            $session_data['sessionstorage'] = $session_data['sessionstorage'] === '{}' ? '{}' : gzinflate(base64_decode($session_data['sessionstorage'])) ?? '';
            $session_data['console'] = empty($session_data['console']) ? '' : gzinflate(base64_decode($session_data['console'])) ?? '';
        }

        return $session_data ?? ['dom' => '', 'localstorage' => '', 'sessionstorage' => '', 'console' => ''];
    }
}