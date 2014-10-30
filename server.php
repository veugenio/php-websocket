<?php

class HandShake {
	public static function response(ServerInterface $server, $client) {
		$server = $server;
		$data = $server->read($client);
		Logger::write('Data: ' . $data);

		$response = self::createResponse($data);
		$server->write($client, $response);
	}

	private static function extractKey($buf) {
		if (!preg_match('/Sec\-WebSocket\-Key: (.+)/', $buf, $match)) {
			return '';
		}
		return $match[1];
	}
	private static function encode($buf) {
		$magicString = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
		$key = trim(self::extractKey($buf));
		return base64_encode(sha1($key . $magicString, true));
	}
	private static function createResponse($data) {
		$keyAccept = self::encode($data);
		/* \r\nSec-WebSocket-Protocol: chat */
		$response = "HTTP/1.1 101 Switching Protocols\r\n" . 
					"Upgrade: websocket\r\n" .
					"Connection: Upgrade\r\n" . 
					"Sec-WebSocket-Accept: $keyAccept\r\n\r\n"; 
		return $response;
	}
}
class Logger {
	public static function write($message) {
		print_r($message);
		print PHP_EOL;
	}
}
interface ServerInterface {

	public function write($client, $message);
	public function read($client);

}

class Server implements ServerInterface
{
    protected $socket;
    protected $clients = [];
    protected $changed;
    protected $newClient;

    private function createLocalSocket() {
    	
        if (!$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) die('Falha ao criar o socket do server');
        socket_bind($socket, '127.0.0.1', 9090);
        socket_listen($socket);

        while (true) {
        	if (!$server = socket_accept($this->socket)) continue;
        	Logger::write('Servidor conectado!');
        	while (true) {
				$buf = '';
				$bytes_received = socket_recv($server, $buf, 65536, 0);
				if ($bytes_received == null) break;
				Logger::write('Recebido: ' . $buf);
				$this->sendMessageToAll('mensagem recebida no servidor');
			}
        }
    }
    
    function __construct($host = 'localhost', $port = 8080)
    {
        set_time_limit(0);
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        //bind socket to specified host
        socket_bind($socket, 0, $port);
        //listen to port
        socket_listen($socket);
        $this->socket = $socket;
        // socket para conexões locais não rola... ou faz um ou faz outro
    	// $this->createLocalSocket();
    }
    
    function __destruct()
    {
        foreach($this->clients as $client) {
            socket_close($client);
        }
        socket_close($this->socket);
    }
    
    function run()
    {
        while(true) {
            $this->waitForChange();
            $this->checkNewClients();
            $this->checkMessageRecieved();
            $this->checkDisconnect();
        }
    }
    
    function checkDisconnect()
    {
        foreach ($this->changed as $changed_socket) {
            $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
            if ($buf !== false) { // check disconnected client
                continue;
            }
            // remove client for $clients array
            $found_socket = array_search($changed_socket, $this->clients);
            socket_getpeername($changed_socket, $ip);
            unset($this->clients[$found_socket]);
            $response = 'client ' . $ip . ' has disconnected';
            $this->sendMessageToAll($response);
        }
    }
    
    function checkMessageRecieved()
    {
        foreach ($this->changed as $key => $socket) {
            $buffer = null;
            while(socket_recv($socket, $buffer, 1024, 0) >= 1) {
            	$message = $this->unmask($buffer);
            	Logger::write($message);
                $this->sendMessageToAll($message);
                unset($this->changed[$key]);
                break;
            }
        }
    }
    
    function waitForChange()
    {
        //reset changed
        $this->changed = array_merge([$this->socket], $this->clients);
        //variable call time pass by reference req of socket_select
        $null = null;
        //this next part is blocking so that we dont run away with cpu
        socket_select($this->changed, $null, $null, null);
    }
    
    function checkNewClients()
    {
        if (!in_array($this->socket, $this->changed)) {
            return; //no new clients
        }
        $newClient = $this->accept(); //accept new socket
        $this->clients[] = $newClient;
        HandShake::response($this, $newClient);
        $this->sendMessageToAll('Novo cliente conectado: ' . $newClient);
        unset($this->changed[0]);
    }
    public function accept()
    {
    	$this->newClient = socket_accept($this->socket);
    	return $this->newClient;
    }

    public function read($client) 
    {
    	$data = socket_read($client, 1024);
        return $data;
    }

    public function write($client, $message) 
    {
    	Logger::write('Write message: ' . $message);
    	@socket_write($client, $message,strlen($message));
    }

    private function unmask($text) {
		$length = ord($text[1]) & 127;
		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		}
		elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		}
		else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}

	public function sendMessage($client, $data)
	{
		$header = " ";
		$header[0] = chr(0x81);
		$header_length = 1;

		//Payload length:  7 bits, 7+16 bits, or 7+64 bits
		$dataLength = strlen($data);

		//The length of the payload data, in bytes: if 0-125, that is the payload length.  
		if($dataLength <= 125) {
			$header[1] = chr($dataLength);
			$header_length = 2;
		} elseif ($dataLength <= 65535) {
			// If 126, the following 2 bytes interpreted as a 16
			// bit unsigned integer are the payload length. 
			$header[1] = chr(126);
			$header[2] = chr($dataLength >> 8);
			$header[3] = chr($dataLength & 0xFF);
			$header_length = 4;
		} else {
			// If 127, the following 8 bytes interpreted as a 64-bit unsigned integer (the 
			// most significant bit MUST be 0) are the payload length. 
			$header[1] = chr(127);
			$header[2] = chr(($dataLength & 0xFF00000000000000) >> 56);
			$header[3] = chr(($dataLength & 0xFF000000000000) >> 48);
			$header[4] = chr(($dataLength & 0xFF0000000000) >> 40);
			$header[5] = chr(($dataLength & 0xFF00000000) >> 32);
			$header[6] = chr(($dataLength & 0xFF000000) >> 24);
			$header[7] = chr(($dataLength & 0xFF0000) >> 16);
			$header[8] = chr(($dataLength & 0xFF00 ) >> 8);
			$header[9] = chr( $dataLength & 0xFF );
			$header_length = 10;
		}

		$result = socket_write($client, $header . $data, strlen($data) + $header_length);
		//$result = socket_write($client, chr(0x81) . chr(strlen($data)) . $data, strlen($data) + 2);
		if ( !$result ) {
		     $this->disconnect($client);
		     $client = false;
		}
	}

	public function disconnect($client) {
		return socket_shutdown($client);
	}
    
    public function sendMessageToAll($message)
    {
        foreach($this->clients as $client)
        {
        	Logger::write('Mensagem enviada ' . $message . ', ' . $client);
        	$this->sendMessage($client, $message);
        }
        return true;
    }
};

$server = new Server('127.0.0.1', '8080');
$server->run();

