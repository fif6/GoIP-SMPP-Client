<?php

// SMPP client for sending SMS via Hybertone GoIP4 GSM VoIP gateway.


class GoIP_SMPP_Client {
	private static $rSock = FALSE;
	private static $iSequenceNumber = 1;

	private static $smpp_configured = false;
	private static $smpp_ip = '0.0.0.0';
	private static $smpp_port = -1;
	private static $smpp_login = '';
	private static $smpp_passwd = '';
	private static $tcp_connect_timeout = 2; // sec. TCP connection establishment timeout

	private static $log_func = '';

	private static function dolog($level, $message) {
		$message = "SMPPc ".$message;
		if ( self::$log_func == '' ) {
			echo strtoupper($level).": {$message}\n";
		} else {
			call_user_func(self::$log_func, $message, $level);
		}
	}
	
	
	public static function Configure($ip, $port, $login, $passwd) {
		self::$smpp_configured = true;
		
		if ( long2ip(ip2long($ip)) != '0.0.0.0' ) {
			self::$smpp_ip = $ip;
		} else {
			self::dolog('error', "Bad SMPP ip: {$host}");
			self::$smpp_configured = false;
		}
		
		if ( $port >= 0 && $port <= 65535 ) {
			self::$smpp_port = $port;
		} else {
			self::dolog('error', "Bad SMPP port: {$port}");
			self::$smpp_configured = false;
		}
		
		if ( strlen($login) > 0 ) {
			self::$smpp_login = $login;
		} else {
			self::dolog('error', "Bad SMPP login: EMPTY STRING!");
			self::$smpp_configured = false;
		}
		
		if ( strlen($passwd) > 0 ) {
			self::$smpp_passwd = $passwd;
		} else {
			self::dolog('error', "Bad SMPP passwd: EMPTY STRING!");
			self::$smpp_configured = false;
		}
		
		return ( self::$smpp_configured == true ) ? true : false;
	}


	private static function Connect() {
		self::dolog('debug', "> connect()");
		
		if ( self::$smpp_configured == false ) {
			self::dolog('error', "Network parameters aren't configured!");
			return false;
		}

		self::$rSock = @fsockopen(self::$smpp_ip, self::$smpp_port, $errno, $errstr, self::$tcp_connect_timeout);
		if ( self::$rSock === false ) {
			self::dolog('error', "TCP socket connection to ".self::$smpp_ip.":".self::$smpp_port." failed! $errstr ($errno)");
			return false;
		}
		self::dolog('debug', "TCP socket connection to ".self::$smpp_ip.":".self::$smpp_port." is successful!");

		// all is ok
		if ( false === self::smpp_bind() ) return false;
		//if ( false === self::smpp_unbind() ) return false;
		return true;
	}

	private static function smpp_bind($sSystemType = '') {
		self::dolog('debug', "> smpp_bind()");
		$sPDU = pack("a" . strlen(self::$smpp_login) . "xa" . strlen(self::$smpp_passwd) . "xa" . strlen($sSystemType) . "xCCCx", self::$smpp_login, self::$smpp_passwd, $sSystemType, 0x34, 5, 1); // body
		$sPDU = pack("NNNN", strlen($sPDU) + 16, 0x09/*BIND_TRANCEIVER*/, 0, self::$iSequenceNumber) . $sPDU; // header + body
		// 0x02 bind-transmitter, 0x09 bind-tranceiver
		if ( false === self::sendPdu($sPDU) ) return false;
		sleep(1); // need to sleep before reading nonblocked socket
		if ( false === $sPDU = self::readPduToArr() ) return false;
		if( $sPDU["status"] !== 0 ) {
			self::dolog('error', 'SMSC bind error!');
			return false;
		}
		self::$iSequenceNumber++;
		self::dolog('debug', "smpp_bind:readPduToArr => ".print_r($sPDU,true) );
		return true;
	}


	private static function smpp_unbind() {
		self::dolog('debug', "> smpp_unbind()");
		$sPDU = pack("NNNN", 16, 0x06/*UNBIND*/, 0, self::$iSequenceNumber);
		//return self::sendPdu($sPDU);
		if ( false === self::sendPdu($sPDU) ) return false;
		sleep(1); // need to sleep before reading nonblocked socket
		if ( false === $sPDU = self::readPduToArr() ) return false;
		self::dolog('debug', "smpp_unbind:readPduToArr => ".print_r($sPDU,true) );
		return true;

	}


	//private function __destruct() {
	//	self::Disconnect();
	//}

	public static function Disconnect() {
		self::dolog('debug', "> Disconnect()");
		if ( isset(self::$rSock) && is_resource(self::$rSock) ) {
			self::smpp_unbind();
			fclose(self::$rSock);
		}
	}


	private static function sendPdu($sPDU) {
		self::dolog('debug', "> sendPdu()");
		$iLength = strlen($sPDU);
		//echo '$sPDU => '; print_r($sPDU); echo "\n";
		self::dolog('debug', "iLength=$iLength");
		self::dolog('debug', "self::\$iSequenceNumber=".self::$iSequenceNumber);

		stream_set_blocking(self::$rSock, true);

		if ( !self::$rSock ) {
			self::dolog('error', "rSock isn't connected in sendPdu");
			return false;
		}
	
		if ( $iLength != fwrite(self::$rSock, $sPDU) ) {
			self::dolog('error', "socket fwrite() failed in sendPdu. Reason is: ".socket_strerror(socket_last_error()) );
			return false;
		}

		return true;
	}


	private static function readPdu() {
		self::dolog('debug', "> readPdu()");
		$sPDU = '';
		$bodyLen = 0;
		stream_set_blocking(self::$rSock, false);

		if ( $sPDU = fread(self::$rSock, 1) ) {
			// 1 byte recved
			self::dolog('debug', "OK! 1byte recved");
			stream_set_blocking(self::$rSock, true);
			$sPDU .= fread(self::$rSock, 15);
			if ( strlen($sPDU) != 16 ) {
				self::dolog('error', "BAD! \$sPDU len is: ".strlen($sPDU) );
				return false;
			}

			self::dolog('debug', "readPdu:unpack header");
			$aHeader = unpack("N4", $sPDU);
			self::dolog('debug', "readPdu:\$aHeader => ".print_r($aHeader,true));
			//$sPDU .= socket_read(self::$rSocket, $aHeader[1] - 16); // body
			$bodyLen = $aHeader[1] - 16;
			if ( $bodyLen > 0 ) $sPDU .= fread(self::$rSock, $bodyLen); // body
		} else {
			self::dolog('debug', "NO 1byte were recved on readPdu");
			return false;
		}
		
		return $sPDU;
	}

	private static function readPduToArr() {
		self::dolog('debug', "> readPduToArr()");
		if ( false === $sPDU = self::readPdu() ) return false; // if readPdu returns false
		return unpack("Nlen/Ncmd_id/Nstatus/Nseq/a*data", $sPDU);
	}


	public static function Send($sSmsText = '', $sPhoneNum = '', $iSmsCount = 2, $sSender = ".", $iValid = "", $bUse_tlv = TRUE, $iTime = "") {
		self::dolog('debug', "> Send()");
		
		if ( !self::$smpp_configured ) { // if not being configured early
			return false; // break in smsc host not configured
		}
		
		if ( !self::$rSock ) { // if not being connected early
			if ( false === self::connect() ) return false;
		}
		
		//$sSmsText = parent::CutSms($sSmsText, $iSmsCount);
		
		if( !strlen($sSmsText) ) {
			self::dolog('error', "GoIp_SMPP_Client: Can't send a message - no message text.");
			return false;
		}

		if( preg_match('/[`\x80-\xff]/', $sSmsText) ) { // is UCS chars
			$sSmsText = iconv("UTF-8", "UTF-16BE", $sSmsText);
			$iCoding = 2; // UCS2
		} else {
			$iCoding = 0; // 7bit
		}

		self::dolog('debug', "\$iCoding={$iCoding}"); //debug

		if ( !$bUse_tlv && strlen($sSmsText) > 255 ) $bUse_tlv = TRUE; // auto enable TLV (Tag, Length, Value)
		$iSmsLength = strlen($sSmsText);
		
		if ( $iValid ) {
			$iValid = min((int)$iValid, 24 * 60);
			$iValid = sprintf('0000%02d%02d%02d00000R', (int)($iValid / 1440), ($iValid % 1440) / 60, $iValid % 60);
		}
		
		/*if ($iTime) {
			if (strlen($iTime) == 12) {
				preg_match('~^(\d\d)(\d\d)(\d{4})(\d\d)(\d\d)$~', $iTime, $m);
				$iTime = mktime($m[4], $m[5], 0, $m[2], $m[1], $m[3]);
			}

			$tz = (int)(date('Z', $iTime) / (15 * 60));
			$iTime = date('ymdHi', $iTime).'000'.str_pad(abs($tz), 2, '0', STR_PAD_LEFT).($tz >= 0 ? '+' : '-');
		}*/
		
		$pdu = pack("xCCa" . strlen($sSender) . "xCCa" . strlen($sPhoneNum) . "xCCCa" . strlen($iTime) . "xa" . strlen($iValid) . "xCCCC", // body
				5,		// source_addr_ton
				1,		// source_addr_npi
				$sSender,	// source_addr
				1,		// dest_addr_ton
				1,		// dest_addr_npi
				$sPhoneNum,	// destination_addr
				0,		// esm_class
				0,		// protocol_id
				3,		// priority_flag
				$iTime,		// schedule_delivery_time
				$iValid,	// validity_period
				0,		// registered_delivery_flag
				0,		// replace_if_present_flag
				$iCoding * 4,	// data_coding
				0) .		// sm_default_msg_id
			
				($bUse_tlv ? "\0\x04\x24" . pack("n", $iSmsLength) : chr($iSmsLength)) . // TLV message_payload tag OR sm_length + short_message
				
				$sSmsText;    // short_message
			
		$pdu = pack("NNNN", strlen($pdu) + 16, 0x04/*SUBMIT_SM*/, 0, self::$iSequenceNumber) . $pdu; // header + body
		
		if ( false === self::sendPdu($pdu) ) return false; // message id or false on error
		sleep(1); // !!!!! before read from unblocked socket
		for ($i=1; $i<=100; $i++) {
			$aReply = self::readPduToArr();
			self::dolog('info', "Cycle $i: ".print_r($aReply,true) );
			if ( is_array($aReply) && $aReply['cmd_id'] != 5 && $aReply['seq'] == self::$iSequenceNumber && $aReply['status'] == 0 ) {
				self::$iSequenceNumber++;
				//self::dolog('info', "******* PDU! *********");
				return (int)$aReply['data'];
			}
			usleep(200000); // sleep 0.2 sec
		}
		//if ( false === self::smpp_unbind() ) return false;
		return false;
	}


	public static function BuffDummyRead() {
		self::dolog('debug', "> BuffDummyRead()");

		if ( !self::$smpp_configured ) { // if not being configured early
			return false; // break in smsc host not configured
		}
		
		if ( !self::$rSock ) { // if not being connected early
			if ( false === self::connect() ) return false; // break in couldnt connect to smsc
		}

		sleep(1); // !!!!! before reading from unblocked socket
		for ($i=1; $i<=2; $i++) {
			$aReply = self::readPduToArr();
			self::dolog('info', "read Cycle $i; readPduToArr reply is ".print_r($aReply,true) );
			usleep(2000000); // sleep 2.0 sec
		}
		return false;
	}
}



?>