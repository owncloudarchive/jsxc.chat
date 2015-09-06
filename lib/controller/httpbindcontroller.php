<?php

namespace OCA\OJSXC\Controller;

use OCA\OJSXC\Db\StanzaMapper;
use OCA\OJSXC\Db\MessageMapper;
use OCA\OJSXC\Http\XMLResponse;
use OCA\OJSXC\StanzaHandlers\IQ;
use OCA\OJSXC\StanzaHandlers\Message;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;


use OCP\ISession;
use Sabre\Xml\Writer;
use Sabre\Xml\Reader;
use Sabre\Xml\LibXMLException;

class HttpBindController extends Controller {

	private $userId;

	const MESSAGE=0;
	const IQ=1;
	const PRESENCE=2;
	const BODY=2;

	private $pollingId;

	/**
	 * @var Session OCP\ISession
	 */
	private $session;

	/**
	 * @var MessageMapper OCA\OJSXC\Db\MessageMapper
	 */
	private $messageMapper;

	/**
	 * @var StanzaMapper OCA\OJSXC\Db\StanzaMapper
	 */
	private $stanzaMapper;

	public function __construct($appName,
	                            IRequest $request,
								$userId,
								ISession $session,
								MessageMapper $messageMapper,
								StanzaMapper $stanzaMapper) {
		parent::__construct($appName, $request);
		$this->userId = $userId;
		$this->pollingId = time();
		$this->session = $session;
		$this->messageMapper = $messageMapper;
		$this->stanzaMapper = $stanzaMapper;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index() {
		$input = file_get_contents('php://input');
		$host = '33.33';
		if (!empty($input)){
			// replace invalid XML by valid XML one
			$input = str_replace("<vCard xmlns='vcard-temp'/>", "<vCard xmlns='jabber:vcard-temp'/>", $input);
			$reader = new Reader();
			$reader->xml($input);
			$reader->elementMap = [
				'{jabber:client}message' => 'Sabre\Xml\Element\KeyValue',
			];

			try {
				$stanzas = $reader->parse();
			} catch (LibXMLException $e){
				echo $e;
			}
			$stanzas = $stanzas['value'];
			$results = [];
			foreach($stanzas as $stanza) {
				$stanzaType = $this->getStanzaType($stanza);
				if ($stanzaType === self::MESSAGE) {
					$messageHandler = new Message($stanza, $this->userId, $host, $this->messageMapper);
					$messageHandler->handle();
				} else if ($stanzaType === self::IQ){
					$iqHandler = new IQ($stanza, $this->userId, $host);
					$results[] = $iqHandler->handle();
				}
			}
			if(count($results) > 0){
				$xmlWriter = new Writer();
				$xmlWriter->openMemory();
				$xmlWriter->startElement('body');
				$xmlWriter->writeAttribute('xmlns', 'http://jabber.org/protocol/httpbind');
				foreach ($results as $result) {
					if (!is_null($result)){
						$xmlWriter->write($result);
					}
				}
				$xmlWriter->endElement();
				return new XMLResponse($xmlWriter->outputMemory());
			}
		}

		// Start long polling
		$recordFound = false;
		$cicles = 0;
		do {
			try {
				$cicles++;
				$stanzas = $this->stanzaMapper->findByTo($this->userId . '@' . $host);

				$xmlWriter = new Writer();
				$xmlWriter->openMemory();
				$xmlWriter->startElement('body');
				$xmlWriter->writeAttribute('xmlns', 'http://jabber.org/protocol/httpbind');

				foreach ($stanzas as $stanz) {
					$xmlWriter->write($stanz);
				}

				$xmlWriter->endElement();
				$recordFound = true;

				return new XMLResponse($xmlWriter->outputMemory());
			} Catch (DoesNotExistException $e) {
				sleep(1);
				$recordFound = false;
			}
		} while ($recordFound === false && $cicles < 10);
		if (!$recordFound) {
			return $this->returnEmpty();
		}
	}

	private function returnEmpty(){
		$xmlWriter = new Writer();
		$xmlWriter->openMemory();
		$xmlWriter->write([
			[
				'name' => 'body',
				'attributes' => [
					'xmlns' => 'http://jabber.org/protocol/httpbind',
				],
				'value' => '',
			]
		]);

		return new XMLResponse($xmlWriter->outputMemory());
	}

	private function getStanzaType($stanza){
		switch($stanza['name']){
			case '{jabber:client}message':
				return self::MESSAGE;
				break;
			case '{jabber:client}iq':
				return self::IQ;
				break;
			case '{jabber:client}presence':
				return self::PRESENCE;
				break;
			default:
				return self::BODY;
				break;
		}
	}


}