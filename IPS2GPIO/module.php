<?php
// geändert von P.G.

// Autoloader für phpseclib
class AutoLoaderPHPSecLib {
	private $namespace;

	public function __construct($namespace = null) {
		$this->namespace = $namespace;
	}

	public function register(): void {
		spl_autoload_register([$this, 'loadClass']);
	}

	public function loadClass($className): void {
		$LibPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'phpseclib' . DIRECTORY_SEPARATOR;
		$file    = $LibPath . str_replace(['\\', 'phpseclib3'], [DIRECTORY_SEPARATOR, 'phpseclib'], $className) . '.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}
}

class IPS2GPIO_IO extends IPSModule {
	private $Socket = false;

	public function __construct($InstanceID) {
		parent::__construct($InstanceID);
	}

	public function __destruct() {
		if ($this->Socket) {
			socket_close($this->Socket);
		}
	}

	// =========================================================
	// IPSModule-Basismethoden
	// =========================================================

	public function Create() {
		parent::Create();
		$this->RegisterMessage(0, IPS_KERNELSTARTED);

		$this->RegisterPropertyBoolean("Open",        false);
		$this->RegisterPropertyString("IPAddress",    "127.0.0.1");
		$this->RegisterPropertyString("User",         "User");
		$this->RegisterPropertyString("Password",     "Passwort");
		$this->RegisterPropertyInteger("MUX",         0);
		$this->RegisterPropertyInteger("OW",          0);
		$this->RegisterPropertyInteger("I2C0",        0);
		$this->RegisterPropertyString("Raspi_Config", "");
		$this->RegisterPropertyString("I2C_Devices",  "");
		$this->RegisterPropertyString("OW_Devices",   "");
		$this->RegisterPropertyBoolean("Multiplexer", false);
		$this->RegisterPropertyBoolean("AutoRestart", true);
		$this->RegisterPropertyBoolean("AudioDAC",    false);
		$this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

		$this->SetBuffer("PinNotify",       serialize([]));
		$this->SetBuffer("PinPossible",     serialize([]));
		$this->SetBuffer("PinUsed",         serialize([]));
		$this->SetBuffer("OWDeviceArray",   serialize([]));
		$this->SetBuffer("OWInstanceArray", serialize([]));
		$this->SetBuffer("I2C_Handle",      serialize([]));
		$this->SetBuffer("I2C_InstanceMap", serialize([]));
		// Serial_Handle mit -1 vorbelegen – GetBuffer liefert sonst "",
		// was als 0 gepackt wird -> pigpiod antwortet mit PI_BAD_HANDLE.
		$this->SetBuffer("Serial_Handle",   -1);

		$this->RegisterVariableString("Hardware",        "Hardware",            "",                10);
		$this->RegisterVariableInteger("SoftwareVersion","SoftwareVersion",     "",                20);
		$this->RegisterVariableInteger("LastKeepAlive",  "Letztes Keep Alive",  "~UnixTimestamp",  30);
		$this->RegisterVariableBoolean("PigpioStatus",   "Pigpio Status",       "~Alert.Reversed", 40);
	}

	public function GetConfigurationForm() {
		$CodeName = trim(shell_exec("bash -c 'source /etc/os-release && echo \$VERSION_CODENAME'"));
		$OSBit    = (PHP_INT_SIZE * 8) . "Bit";

		$arrayStatus   = [];
		$arrayStatus[] = ["code" => 101, "icon" => "inactive", "caption" => "Instanz wird erstellt"];
		// Caption 102 dynamisch: waehrend PIGPIOD_Restart() zeigt RestartMode=1 "Bitte warten!"
		$caption102    = ($this->GetBuffer("RestartMode") == "1") ? "Bitte warten!" : "Instanz ist aktiv";
		$arrayStatus[] = ["code" => 102, "icon" => "active",   "caption" => $caption102];
		$arrayStatus[] = ["code" => 104, "icon" => "inactive", "caption" => "Instanz ist inaktiv"];
		$arrayStatus[] = ["code" => 200, "icon" => "error",    "caption" => "Instanz ist fehlerhaft"];

		$sep           = "_____________________________________________________________________________________________________________________";
		$arrayElements = [];
		$arrayElements[] = ["type" => "CheckBox",          "name" => "Open",      "caption" => "Aktiv"];
		$arrayElements[] = ["type" => "Label",             "caption" => $sep];
		$arrayElements[] = ["type" => "ValidationTextBox", "name" => "IPAddress", "caption" => "IP"];
		$arrayElements[] = ["type" => "Label",             "caption" => $sep];
		$arrayElements[] = ["type" => "Label",             "caption" => "Zugriffsdaten des Raspberry Pi SSH:"];
		$arrayElements[] = ["type" => "RowLayout", "items" => [
			["type" => "ValidationTextBox", "name" => "User",     "caption" => "User"],
			["type" => "PasswordTextBox",   "name" => "Password", "caption" => "Password"],
		]];
		$arrayElements[] = ["type" => "Label", "caption" => $sep];
		$arrayElements[] = ["type" => "Label", "caption" => "Detaillierung der genutzten I\xC2\xB2C-Schnittstelle:"];
		$arrayElements[] = ["type" => "RowLayout", "items" => [
			["type" => "Select", "name" => "MUX", "caption" => "MUX-Auswahl", "options" => [
				["label" => "Kein MUX",               "value" => 0],
				["label" => "TCA9548a Adr. 112/0x70", "value" => 1],
				["label" => "PCA9542 Adr. 112/0x70",  "value" => 2],
			]],
			["type" => "Select", "name" => "I2C0", "caption" => "Nutzung der I\xC2\xB2C-Schnittstelle 0", "options" => [
				["label" => "Nein", "value" => 0],
				["label" => "Ja",   "value" => 1],
			]],
		]];
		$arrayElements[] = ["type" => "Label",  "caption" => $sep];
		$arrayElements[] = ["type" => "Select", "name" => "OW", "caption" => "1-Wire Auswahl", "options" => [
			["label" => "Kein DS2482",         "value" => 0],
			["label" => "DS2482 Adr. 24/0x18", "value" => 1],
		]];
		$arrayElements[] = ["type" => "Label", "caption" => $sep];
		$arrayElements[] = ["type" => "Label", "caption" => "Analyse der Raspberry Pi Konfiguration auf $CodeName $OSBit von P.G.:"];

		$ServiceArray    = unserialize($this->CheckConfig());
		$arrayElements[] = ["type" => "List", "name" => "Raspi_Config", "caption" => "Konfiguration",
			"rowCount" => 5, "add" => false, "delete" => false,
			"sort"     => ["column" => "ServiceTyp", "direction" => "ascending"],
			"columns"  => [
				["label" => "Service", "name" => "ServiceTyp",    "width" => "700px", "add" => ""],
				["label" => "Status",  "name" => "ServiceStatus", "width" => "100px", "add" => ""],
			],
			"values" => [
				["ServiceTyp" => "1-Wire-Server",                  "ServiceStatus" => $ServiceArray["1-Wire-Server"]["Status"],          "rowColor" => $ServiceArray["1-Wire-Server"]["Color"]],
				["ServiceTyp" => "I\xC2\xB2C",                     "ServiceStatus" => $ServiceArray["I2C"]["Status"],                    "rowColor" => $ServiceArray["I2C"]["Color"]],
				["ServiceTyp" => "PIGPIO Server",                  "ServiceStatus" => $ServiceArray["PIGPIO Server"]["Status"],          "rowColor" => $ServiceArray["PIGPIO Server"]["Color"]],
				["ServiceTyp" => "Serielle Schnittstelle (RS232)", "ServiceStatus" => $ServiceArray["Serielle Schnittstelle"]["Status"], "rowColor" => $ServiceArray["Serielle Schnittstelle"]["Color"]],
				["ServiceTyp" => "Shell Zugriff",                  "ServiceStatus" => $ServiceArray["Shell Zugriff"]["Status"],          "rowColor" => $ServiceArray["Shell Zugriff"]["Color"]],
			],
		];
		$arrayElements[] = ["type" => "Label", "caption" => $sep];

		// 1-Wire-Devices-Tabelle
		$arrayOWColumns = [
			["label" => "Typ",        "name" => "DeviceTyp",    "width" => "200px", "add" => ""],
			["label" => "Serien-Nr.", "name" => "DeviceSerial", "width" => "380px", "add" => ""],
			["label" => "Instanz ID", "name" => "InstanceID",   "width" => "120px", "add" => ""],
			["label" => "Status",     "name" => "DeviceStatus", "width" => "100px", "add" => ""],
		];
		$arraySortOW = ["column" => "DeviceTyp", "direction" => "ascending"];

		if ($this->ConnectionTest() && $this->ReadPropertyBoolean("Open") && $this->GetBuffer("I2C_Enabled") == 1) {
			if ($this->GetBuffer("OW_Handle") >= 0) {
				$this->OWSearchStart();
				$OWDeviceArray = unserialize($this->GetBuffer("OWDeviceArray"));
				if (count($OWDeviceArray, COUNT_RECURSIVE) >= 4) {
					$arrayOWValues = [];
					foreach ($OWDeviceArray as $entry) {
						$instanceID = isset($entry[2]) ? intval($entry[2]) : 0;
						if ($instanceID > 0 && !@IPS_InstanceExists($instanceID)) continue;
						$arrayOWValues[] = [
							"DeviceTyp"    => $entry[0],
							"DeviceSerial" => $entry[1],
							"InstanceID"   => ($instanceID > 0) ? $instanceID : "Kein(e)",
							"DeviceStatus" => $entry[3],
							"rowColor"     => $entry[4],
						];
					}
					if (count($arrayOWValues) > 0) {
						$arrayElements[] = ["type" => "List", "name" => "OW_Devices", "caption" => "1-Wire-Devices",
							"rowCount" => count($arrayOWValues), "add" => false, "delete" => false,
							"sort" => $arraySortOW, "columns" => $arrayOWColumns, "values" => $arrayOWValues];
					} else {
						$arrayElements[] = ["type" => "Label", "caption" => "Es wurden keine aktiven 1-Wire-Devices gefunden."];
					}
				} else {
					$arrayElements[] = ["type" => "Label", "caption" => "Es wurden keine 1-Wire-Devices gefunden."];
				}
				$arrayElements[] = ["type" => "Label", "caption" => $sep];
			}
		}

		// I2C-Devices-Tabelle
		$arrayI2CColumns = [
			["label" => "Typ",                 "name" => "DeviceTyp",     "width" => "200px", "add" => ""],
			["label" => "Adresse (dez / hex)", "name" => "DeviceAddress", "width" => "200px", "add" => ""],
			["label" => "Bus",                 "name" => "DeviceBus",     "width" => "180px", "add" => ""],
			["label" => "Instanz ID",          "name" => "InstanceID",    "width" => "120px", "add" => ""],
			["label" => "Status",              "name" => "DeviceStatus",  "width" => "100px", "add" => ""],
		];

		if ($this->ConnectionTest() && $this->ReadPropertyBoolean("Open") && $this->GetBuffer("I2C_Enabled") == 1) {
			$I2CDeviceArray = unserialize($this->SearchI2CDevices());
			if (is_array($I2CDeviceArray) && count($I2CDeviceArray) > 0) {
				$arrayI2CValues = [];
				foreach ($I2CDeviceArray as $entry) {
					$instanceID = isset($entry[3]) ? intval($entry[3]) : 0;
					if ($instanceID > 0 && !@IPS_InstanceExists($instanceID)) continue;
					$arrayI2CValues[] = [
						"DeviceTyp"     => $entry[0],
						"DeviceAddress" => $entry[1] . " / 0x" . strtoupper(dechex($entry[1])),
						"DeviceBus"     => $entry[2],
						"InstanceID"    => ($instanceID > 0) ? $instanceID : "Kein(e)",
						"DeviceStatus"  => $entry[4],
						"rowColor"      => $entry[5],
					];
				}
				if (count($arrayI2CValues) > 0) {
					$arrayElements[] = ["type" => "List", "name" => "I2C_Devices", "caption" => "I\xC2\xB2C-Devices",
						"rowCount" => count($arrayI2CValues), "add" => false, "delete" => false,
						"sort" => ["column" => "DeviceTyp", "direction" => "ascending"],
						"columns" => $arrayI2CColumns, "values" => $arrayI2CValues];
				} else {
					$arrayElements[] = ["type" => "Label", "caption" => "Es wurden keine aktiven I\xC2\xB2C-Devices gefunden."];
				}
				$arrayElements[] = ["type" => "Label", "caption" => $sep];
			} else {
				$arrayElements[] = ["type" => "Label", "caption" => "Es wurden keine I\xC2\xB2C-Devices gefunden."];
				$arrayElements[] = ["type" => "Label", "caption" => $sep];
			}
		}

		$arrayElements[] = ["type" => "Label",    "caption" => "Wird ein Audio Hat wie z.B. Hifiberry parallel verwendet, muss diese Option gewaehlt werden."];
		$arrayElements[] = ["type" => "Label",    "caption" => "Die Nutzung von PWM (Dimmer, RGB, RGBW usw.) ist dann nicht moeglich!"];
		$arrayElements[] = ["type" => "CheckBox", "name"    => "AudioDAC",    "caption" => "Vorhanden"];
		$arrayElements[] = ["type" => "Label",    "caption" => "Fuehrt einen automatischen Restart des PIGPIO aus:"];
		$arrayElements[] = ["type" => "CheckBox", "name"    => "AutoRestart", "caption" => "Auto Restart"];

		$arrayActions = [];
		if ($this->ReadPropertyBoolean("Open")) {
			if ($this->ConnectionTest()) {
				$arrayActions[] = ["type" => "Label",  "caption" => "Startet PIGPIO neu und laedt die Instanzkonfiguration danach automatisch neu:"];
				$arrayActions[] = ["type" => "Button", "caption" => "PIGPIO Restart", "onClick" => 'I2G_PIGPIOD_Restart($id);'];
				$arrayActions[] = ["type" => "Label",  "caption" => "Testet die Verbindung zum MUX:"];
				$arrayActions[] = ["type" => "Button", "caption" => "MUX Test",       "onClick" => 'I2G_SearchSpecialI2CDevices($id, 112);'];
				$arrayActions[] = ["type" => "Label",  "caption" => "Testet die Verbindung zum DS2482/1-Wire:"];
				$arrayActions[] = ["type" => "Button", "caption" => "DS2482 Test",    "onClick" => 'I2G_SearchSpecialI2CDevices($id, 24);'];
			}
		} else {
			$arrayActions[] = ["type" => "Label", "caption" => "Diese Funktionen stehen erst nach Eingabe und Uebernahme der erforderlichen Daten zur Verfuegung!"];
		}
		return json_encode(["status" => $arrayStatus, "elements" => $arrayElements, "actions" => $arrayActions]);
	}

	public function ApplyChanges() {
		parent::ApplyChanges();

		// Phantomzeilen-Fix: OW_Devices/I2C_Devices sind reine Anzeigefelder.
		// Self-Update-Pattern: Properties leeren -> zweiter Durchlauf ohne Phantomzeilen.
		if ($this->ReadPropertyString("OW_Devices") !== "" || $this->ReadPropertyString("I2C_Devices") !== "") {
			IPS_SetProperty($this->InstanceID, "OW_Devices",  "");
			IPS_SetProperty($this->InstanceID, "I2C_Devices", "");
			IPS_ApplyChanges($this->InstanceID);
			return;
		}
		if (IPS_GetKernelRunlevel() != KR_READY) return;

		// Buffer initialisieren
		$this->SetBuffer("ModuleReady",               0);
		$this->SetBuffer("Handle",                    -1);
		$this->SetBuffer("HardwareRev",               0);
		$Typ = array(2 => 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27);
		$this->SetBuffer("PinPossible",               serialize($Typ));
		$this->SetBuffer("PinI2C",                    "");
		$this->SetBuffer("I2CSearch",                 0);
		$this->SetBuffer("I2C_Enabled",               0);
		$this->SetBuffer("I2C_0_Configured",          0);
		$this->SetBuffer("I2C_1_Configured",          0);
		$this->SetBuffer("Serial_Configured",         0);
		$this->SetBuffer("Serial_Handle",             -1);
		$this->SetBuffer("Serial_Display_Configured", 0);
		$this->SetBuffer("Serial_Display_RxD",        -1);
		$this->SetBuffer("Serial_GPS_Configured",     0);
		$this->SetBuffer("Serial_GPS_RxD",            -1);
		$this->SetBuffer("Serial_GPS_Data",           "");
		$this->SetBuffer("Serial_SDS011_Configured",  0);
		$this->SetBuffer("Serial_SDS011_RxD",         -1);
		$this->SetBuffer("1Wire_Configured",          0);
		$this->SetBuffer("SerialNotify",              0);
		$this->SetBuffer("SerialScriptID",            -1);
		$this->SetBuffer("Default_I2C_Bus",           1);
		$this->SetBuffer("Default_Serial_Bus",        0);
		$this->SetBuffer("MUX_Handle",                -1);
		$this->SetBuffer("OW_Handle",                 -1);
		$this->SetBuffer("NotifyBitmask",             -1);
		$this->SetBuffer("LastNotify",                -1);
		$this->SetBuffer("PinNotify",                 serialize([]));
		$this->SetBuffer("owLastDevice",              0);
		$this->SetBuffer("owLastDiscrepancy",         0);
		$this->SetBuffer("owTripletDirection",        1);
		$this->SetBuffer("owTripletFirstBit",         0);
		$this->SetBuffer("owTripletSecondBit",        0);
		$this->SetBuffer("owDeviceAddress_0",         0);
		$this->SetBuffer("owDeviceAddress_1",         0);

		$ParentID = $this->GetParentID();
		$this->RegisterMessage($this->InstanceID, IM_CONNECT);
		$this->RegisterMessage($this->InstanceID, IM_DISCONNECT);
		$this->RegisterMessage($ParentID, IM_CHANGESTATUS);

		if ($ParentID > 0) {
			if (IPS_GetProperty($ParentID, 'Host') != $this->ReadPropertyString('IPAddress'))
				IPS_SetProperty($ParentID, 'Host', $this->ReadPropertyString('IPAddress'));
			if (IPS_GetProperty($ParentID, 'Port') != 8888)
				IPS_SetProperty($ParentID, 'Port', 8888);
			if (IPS_GetProperty($ParentID, 'Open') != $this->ReadPropertyBoolean("Open"))
				IPS_SetProperty($ParentID, 'Open', $this->ReadPropertyBoolean("Open"));
			if (IPS_GetName($ParentID) == "Client Socket")
				IPS_SetName($ParentID, "IPS2GPIO");
			if (IPS_HasChanges($ParentID)) {
				$this->SendDebug("ApplyChanges", @IPS_ApplyChanges($ParentID)
					? "Einrichtung des Client Socket erfolgreich"
					: "Einrichtung des Client Socket nicht erfolgreich!", 0);
			}
		}

		if ($this->ConnectionTest() && $this->ReadPropertyBoolean("Open")) {
			$this->SetSummary($this->ReadPropertyString('IPAddress'));
			$this->SendDebug("ApplyChanges", "Starte Vorbereitung", 0);
			$this->setPigpioStatus(true);
			$this->CheckConfig();
			$this->CommandClientSocket(pack("L*", 17, 0, 0, 0) . pack("L*", 26, 0, 0, 0), 32); // HW+SW-Version
			$this->CommandClientSocket(pack("L*", 27, 0, 0, 0), 16);                           // Waveforms loeschen
			if ($this->GetBuffer("I2C_Enabled") == 1) $this->ResetI2CHandle(0);
			$Handle = $this->ClientSocket(pack("L*", 99, 0, 0, 0));  // Notify starten
			$this->SetBuffer("Handle", $Handle);
			$this->SendDebug("Handle", (int)$Handle, 0);
			if ($this->ReadPropertyInteger("MUX") > 0 && $this->GetBuffer("I2C_Enabled") == 1) {
				$MUX_Handle = $this->CommandClientSocket(pack("L*", 54, 1, 112, 4, 0), 16);
				$this->SetBuffer("MUX_Handle", $MUX_Handle);
				$this->SetBuffer("MUX_Channel", -1);
				$this->SendDebug("MUX Handle", $MUX_Handle, 0);
				if ($MUX_Handle >= 0) $this->SetMUX(0);
			}
			// OW einrichten; DS2482 resetten (verhindert unterbrochenen Zustand nach Neustart)
			if ($this->ReadPropertyInteger("OW") > 0 && $this->GetBuffer("I2C_Enabled") == 1) {
				$OW_Handle = $this->CommandClientSocket(pack("L*", 54, 1, 24, 4, 0), 16);
				$this->SetBuffer("OW_Handle", $OW_Handle);
				$this->SendDebug("OW Handle", $OW_Handle, 0);
				if ($OW_Handle >= 0) $this->DS2482Reset();
			}
			// I2C-Handle/InstanceMap leeren – werden via set_used_i2c neu befuellt
			$this->SetBuffer("I2C_Handle",      serialize([]));
			$this->SetBuffer("I2C_InstanceMap", serialize([]));
			$this->SendDebug("ApplyChanges", "Beende Vorbereitung", 0);
			$this->SetBuffer("ModuleReady", 1);

			$dataID = "{8D44CA24-3B35-4918-9CBD-85A28C0C8917}";
			$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "get_used_i2c"]));
			$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "get_serial"]));
			$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "get_usedpin"]));
			$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "get_start_trigger"]));

			if ($Handle >= 0)
				$this->CommandClientSocket(pack("L*", 19, $Handle, $this->CalcBitmask(), 0), 16);
			$this->setStatusSafe(102);
		} else {
			$this->setStatusSafe(104);
			$this->SetBuffer("ModuleReady", 0);
		}
	}

	public function GetConfigurationForParent() {
		return json_encode([
			"Host" => $this->ReadPropertyString('IPAddress'),
			"Port" => 8888,
			"Open" => $this->ReadPropertyBoolean("Open"),
		]);
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		switch ($Message) {
			case IPS_KERNELSTARTED:
				$this->ApplyChanges();
				break;
			case IM_CONNECT:
				$this->SendDebug("MessageSink", "Instanz " . $SenderID . " wurde verbunden", 0);
				break;
			case IM_DISCONNECT:
				$this->SendDebug("MessageSink", "Instanz " . $SenderID . " wurde getrennt", 0);
				// Fix: is_array()-Guard verhindert Fatal Error bei leerem/ungueltigem Buffer
				$PinUsed = unserialize($this->GetBuffer("PinUsed"));
				if (is_array($PinUsed)) {
					foreach ($PinUsed as $Pin => $InstanceID) {
						if ($InstanceID == $SenderID) {
							unset($PinUsed[$Pin]);
							$this->SendDebug("MessageSink", "Pin " . $Pin . " wurde freigegeben", 0);
						}
					}
					$this->SetBuffer("PinUsed", serialize($PinUsed));
				}
				break;
			case IM_CHANGESTATUS:
				if ($Data[0] == 102) {
					$this->ApplyChanges();
				} elseif ($Data[0] == 200) {
					if ($this->ReadPropertyBoolean("AutoRestart")) $this->ConnectionTest();
					$this->setPigpioStatus(false);
				}
				break;
		}
	}

	// =========================================================
	// ForwardData (Children -> Parent)
	// =========================================================

	public function ForwardData($JSONString) {
		$data   = json_decode($JSONString);
		$Result = -999;
		$dataID = "{8D44CA24-3B35-4918-9CBD-85A28C0C8917}";

		switch ($data->Function) {

		// --- GPIO ---
		case "set_PWM_dutycycle":
			if ($data->Pin >= 0) $Result = $this->CommandClientSocket(pack("L*", 5, $data->Pin, $data->Value, 0), 16);
			break;
		case "get_PWM_dutycycle":
			if ($data->Pin >= 0) $Result = $this->CommandClientSocket(pack("L*", 83, $data->Pin, 0, 0), 16);
			break;
		case "set_PWM_dutycycle_RGB":
			if ($data->Pin_R >= 0 && $data->Pin_G >= 0 && $data->Pin_B >= 0) {
				$Result = $this->CommandClientSocket(
					pack("L*", 5, $data->Pin_R, $data->Value_R, 0) .
					pack("L*", 5, $data->Pin_G, $data->Value_G, 0) .
					pack("L*", 5, $data->Pin_B, $data->Value_B, 0), 48);
			}
			break;
		case "get_PWM_dutycycle_RGB":
			if ($data->Pin_R >= 0 && $data->Pin_G >= 0 && $data->Pin_B >= 0) {
				$Result = serialize([
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_R, 0, 0), 16),
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_G, 0, 0), 16),
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_B, 0, 0), 16),
				]);
			}
			break;
		case "set_PWM_dutycycle_RGBW":
			if ($data->Pin_R >= 0 && $data->Pin_G >= 0 && $data->Pin_B >= 0 && $data->Pin_W >= 0) {
				$Result = $this->CommandClientSocket(
					pack("L*", 5, $data->Pin_R, $data->Value_R, 0) .
					pack("L*", 5, $data->Pin_G, $data->Value_G, 0) .
					pack("L*", 5, $data->Pin_B, $data->Value_B, 0) .
					pack("L*", 5, $data->Pin_W, $data->Value_W, 0), 64);
			}
			break;
		case "get_PWM_dutycycle_RGBW":
			if ($data->Pin_R >= 0 && $data->Pin_G >= 0 && $data->Pin_B >= 0 && $data->Pin_W >= 0) {
				$Result = serialize([
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_R, 0, 0), 16),
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_G, 0, 0), 16),
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_B, 0, 0), 16),
					$this->CommandClientSocket(pack("L*", 83, $data->Pin_W, 0, 0), 16),
				]);
			}
			break;
		case "get_value":
			if ($data->Pin >= 0) $Result = $this->CommandClientSocket(pack("L*", 3, $data->Pin, 0, 0), 16);
			break;
		case "set_value":
			if ($data->Pin >= 0) $Result = $this->CommandClientSocket(pack("L*", 4, $data->Pin, $data->Value, 0), 16);
			break;
		case "set_trigger_value":
			if ($data->Pin >= 0) {
				$this->CommandClientSocket(pack("L*", 4, $data->Pin, 0, 0), 16);
				IPS_Sleep($data->Duration);
				$Result = $this->CommandClientSocket(pack("L*", 4, $data->Pin, 1, 0), 16);
			}
			break;
		case "set_trigger":
			if ($data->Pin >= 0) $Result = $this->CommandClientSocket(pack("L*", 37, $data->Pin, $data->Time, 4, 1), 16);
			break;
		case "set_servo":
			if ($data->Pin >= 0) {
				$Result = $this->CommandClientSocket(pack("L*", 8, $data->Pin, $data->Value, 0), 16);
				$this->SendDebug("ForwardData - set_servo", "Wert: " . $Result, 0);
			}
			break;
		case "get_servo":
			if ($data->Pin >= 0) {
				$Result = $this->CommandClientSocket(pack("L*", 84, $data->Pin, 0, 0), 16);
				$this->SendDebug("ForwardData - get_servo", "Wert: " . $Result, 0);
			}
			break;

		// --- Interne Kommunikation ---
		case "getI2CDeviceArray":
			$Result = ($this->ConnectionTest() && $this->ReadPropertyBoolean("Open")) ? $this->SearchI2CDevices() : false;
			break;
		case "get1WDeviceArray":
			$Result = ($this->ConnectionTest() && $this->ReadPropertyBoolean("Open")) ? $this->OWSearchStart() : false;
			break;
		case "set_usedpin":
			if ($this->GetBuffer("ModuleReady") == 1) {
				if ($data->Pin >= 0) {
					$PinPossible = unserialize($this->GetBuffer("PinPossible"));
					if (in_array($data->Pin, $PinPossible)) {
						$this->SendDebug("set_usedpin", "Pin " . $data->Pin . " verfuegbar", 0);
						$this->SendDataToChildren(json_encode(["DataID" => $dataID,
							"Function" => "status", "Pin" => $data->Pin, "Status" => 102,
							"HardwareRev" => $this->GetBuffer("HardwareRev"), "InstanceID" => $data->InstanceID]));
					} else {
						$this->SendDebug("set_usedpin", "Pin " . $data->Pin . " nicht verfuegbar!", 0);
						IPS_LogMessage("IPS2GPIO Pin: ", "Gewaehlter Pin " . $data->Pin . " ist bei diesem Modell nicht verfuegbar!");
						$this->SendDataToChildren(json_encode(["DataID" => $dataID,
							"Function" => "status", "Pin" => $data->Pin, "Status" => 201,
							"HardwareRev" => $this->GetBuffer("HardwareRev"), "InstanceID" => $data->InstanceID]));
					}
					$PinUsed = unserialize($this->GetBuffer("PinUsed"));
					if (is_array($PinUsed)) {
						if (array_key_exists(intval($data->Pin), $PinUsed)) {
							if ($PinUsed[$data->Pin] != $data->InstanceID && $PinUsed[$data->Pin] != 99999) {
								IPS_LogMessage("IPS2GPIO Pin", "Achtung: Pin " . $data->Pin . " wird mehrfach genutzt!");
								$this->SendDebug("set_usedpin", "Achtung: Pin " . $data->Pin . " wird mehrfach genutzt!", 0);
								$this->SendDataToChildren(json_encode(["DataID" => $dataID,
									"Function" => "status", "Pin" => $data->Pin, "Status" => 200,
									"HardwareRev" => $this->GetBuffer("HardwareRev"), "InstanceID" => $data->InstanceID]));
							}
						}
					}
					$PinUsed[intval($data->Pin)] = $data->InstanceID;
					if (intval($data->Pin) != intval($data->PreviousPin) && intval($data->PreviousPin) > -1)
						unset($PinUsed[intval($data->PreviousPin)]);
					$this->SetBuffer("PinUsed", serialize($PinUsed));
					$this->RegisterMessage($data->InstanceID, IM_CONNECT);
					$this->RegisterMessage($data->InstanceID, IM_DISCONNECT);
					if ($data->Notify) {
						$PinNotify = unserialize($this->GetBuffer("PinNotify"));
						if (!in_array(intval($data->Pin), $PinNotify)) {
							$PinNotify[] = intval($data->Pin);
							$this->SendDebug("set_usedpin", "Pin " . $data->Pin . " dem Notify hinzugefuegt", 0);
						}
						$this->SetBuffer("PinNotify", serialize($PinNotify));
						$this->CommandClientSocket(pack("L*", 19, $this->GetBuffer("Handle"), $this->CalcBitmask(), 0), 16);
						$this->CommandClientSocket(pack("L*", 97, $data->Pin, $data->GlitchFilter, 0), 16);
					}
					// R/W-Mode und ggf. Pull-Up/Down setzen
					if ($data->Modus == 0)
						$this->CommandClientSocket(pack("LLLL", 0, $data->Pin, 0, 0) . pack("LLLL", 2, $data->Pin, $data->Resistance, 0), 32);
					else
						$this->CommandClientSocket(pack("LLLL", 0, $data->Pin, $data->Modus, 0), 16);
				}
				$Result = true;
			} else {
				$Result = false;
			}
			break;
		case "get_GPIO":
			$PinPossible  = unserialize($this->GetBuffer("PinPossible"));
			$PinUsed      = unserialize($this->GetBuffer("PinUsed"));
			$PinFreeArray = is_array($PinUsed) ? array_diff($PinPossible, array_keys($PinUsed)) : $PinPossible;
			$arrayGPIO    = [-1 => "undefiniert"];
			foreach ($PinFreeArray as $v) $arrayGPIO[$v] = "GPIO" . sprintf("%'.02d", $v);
			return serialize($arrayGPIO);

		// --- I2C-Konfiguration ---
		case "set_used_i2c":
			if ($this->GetBuffer("ModuleReady") == 1) {
				$DevicePorts = [0 => "I\xC2\xB2C-Bus 0", 1 => "I\xC2\xB2C-Bus 1"];
				for ($i = 3; $i <= 10; $i++) $DevicePorts[$i] = "MUX I\xC2\xB2C-Bus " . ($i - 3);
				// GPIO-Mode fuer I2C Bus 0 (GPIO 28/29 an P5)
				if ($this->GetBuffer("I2C_0_Configured") == 0 && intval($data->DeviceBus) == 0) {
					$this->CommandClientSocket(pack("LLLL", 0, 28, 4, 0) . pack("LLLL", 0, 29, 4, 0), 32);
					$this->SetBuffer("I2C_0_Configured", 1);
					$this->SendDebug("Set Used I2C", "GPIO-Mode fuer I2C Bus 0 gesetzt", 0);
				}
				// GPIO-Mode fuer I2C Bus 1 (GPIO 0/1 bzw. 2/3 je nach HardwareRev)
				if ($this->GetBuffer("I2C_1_Configured") == 0 && intval($data->DeviceBus) == 1) {
					$PinUsed = unserialize($this->GetBuffer("PinUsed"));
					if ($this->GetBuffer("HardwareRev") <= 3) {
						$PinUsed[0] = $PinUsed[1] = 99999;
						$this->CommandClientSocket(pack("L*", 0, 0, 4, 0) . pack("L*", 0, 1, 4, 0), 32);
					} else {
						$PinUsed[2] = $PinUsed[3] = 99999;
						$this->CommandClientSocket(pack("L*", 0, 2, 4, 0) . pack("L*", 0, 3, 4, 0), 32);
					}
					$this->SetBuffer("PinUsed", serialize($PinUsed));
					$this->SetBuffer("I2C_1_Configured", 1);
					$this->SendDebug("Set Used I2C", "GPIO-Mode fuer I2C Bus 1 gesetzt", 0);
				}
				$I2C_DeviceHandle = unserialize($this->GetBuffer("I2C_Handle"));
				$this->RegisterMessage($data->InstanceID, IM_CONNECT);
				$this->RegisterMessage($data->InstanceID, IM_DISCONNECT);
				$DeviceBus = min(1, intval($data->DeviceBus));
				$Handle    = $this->CommandClientSocket(pack("L*", 54, $DeviceBus, intval($data->DeviceAddress), 4, 0), 16);
				$this->SendDebug("Set Used I2C", "Handle fuer Adr. " . $data->DeviceAddress . " an " . $DevicePorts[intval($data->DeviceBus)] . ": " . $Handle, 0);
				// Schluessel: logischer Bus (DeviceBus) << 7 + Adresse – vermeidet MUX-Kollisionen
				$I2C_DeviceHandle[($data->DeviceBus << 7) + $data->DeviceAddress] = $Handle;
				$this->SetBuffer("I2C_Handle", serialize($I2C_DeviceHandle));
				$I2C_InstanceMap = unserialize($this->GetBuffer("I2C_InstanceMap"));
				if (!is_array($I2C_InstanceMap)) $I2C_InstanceMap = [];
				$I2C_InstanceMap[($data->DeviceBus << 7) + $data->DeviceAddress] = $data->InstanceID;
				$this->SetBuffer("I2C_InstanceMap", serialize($I2C_InstanceMap));
				if ($Handle >= 0) {
					if (intval($data->DeviceBus) >= 3) $this->SetMUX(intval($data->DeviceBus));
					$testResult = $this->CommandClientSocket(pack("L*", 59, $Handle, 0, 0), 16);
					if ($testResult >= 0)
						$this->SendDebug("Set Used I2C", "Test-Lesen auf Adr. " . $data->DeviceAddress . " erfolgreich!", 0);
					else {
						$this->SendDebug("Set Used I2C", "Test-Lesen auf Adr. " . $data->DeviceAddress . " nicht erfolgreich!", 0);
						IPS_LogMessage("IPS2GPIO I2C", "Test-Lesen auf Adr. " . $data->DeviceAddress . " nicht erfolgreich!");
					}
				}
				$Result = true;
			} else {
				$Result = false;
			}
			break;
		case "i2c_get_ports":
			$DevicePorts = [];
			if ($this->ReadPropertyInteger("I2C0") == 1) $DevicePorts[0] = "I\xC2\xB2C-Bus 0";
			$DevicePorts[1] = "I\xC2\xB2C-Bus 1";
			$MUX = $this->ReadPropertyInteger("MUX");
			if ($MUX == 1) { for ($i = 3; $i <= 10; $i++) $DevicePorts[$i] = "MUX I\xC2\xB2C-Bus " . ($i - 3); }
			elseif ($MUX == 2) { for ($i = 3; $i <= 4; $i++) $DevicePorts[$i] = "MUX I\xC2\xB2C-Bus " . ($i - 3); }
			$Result = serialize($DevicePorts);
			break;

		// --- I2C-Lese-/Schreib-Operationen (konsolidiert) ---
		// cmd 61 I2CRB h r – Byte aus Register lesen
		case "i2c_read_byte": case "i2c_BMP180_read": case "i2c_BME280_read":
		case "i2c_BME680_read": case "i2c_PCA9685_Read_Byte": case "i2c_PCF8591_read": case "i2c_ADXL345_read":
			$Result = $this->i2cReadReg(intval($data->DeviceIdent), $data->Register);
			break;
		// cmd 62 I2CWB h r bv – Byte in Register schreiben
		case "i2c_write_byte": case "i2c_PCF8574_write": case "i2c_PCF8583_write": case "i2c_AS3935_write":
		case "i2c_BMP180_write": case "i2c_BME280_write": case "i2c_BME680_write": case "i2c_PCA9685_Write":
		case "i2c_PCF8591_write": case "i2c_SUSV_write": case "i2c_ADXL345_write":
			$Result = $this->i2cWriteReg(intval($data->DeviceIdent), $data->Register, $data->Value);
			break;
		// cmd 63 I2CRW h r – Word aus Register lesen
		case "i2c_read_word": case "i2c_BH1750_read": case "i2c_PCA9685_Read":
			$Result = $this->i2cReadWord(intval($data->DeviceIdent), $data->Register);
			break;
		// cmd 56 I2CRD h count – Bytes lesen (ohne Register)
		case "i2c_read_bytes": case "i2c_AS3935_read": case "i2c_MCP3424_read":
		case "i2c_iAQ_read": case "i2c_EZOCircuit_read":
			$Result = $this->i2cReadBytes(intval($data->DeviceIdent), $data->Count);
			break;
		// cmd 67 I2CRI h r count – Block-Bytes lesen
		case "i2c_read_block_byte": case "i2c_PCF8583_read": case "i2c_BMP180_read_block":
		case "i2c_BME280_read_block": case "i2c_BME680_read_block": case "i2c_MCP23017_read":
		case "i2c_DS3231_read": case "i2c_SUSV_read": case "i2c_ADXL345_read_block":
			$Result = $this->i2cReadBlock(intval($data->DeviceIdent), $data->Register, $data->Count);
			break;
		// cmd 59 I2CRB h – Byte vom Handle lesen (ohne Register)
		case "i2c_read_byte_onhandle": case "i2c_PCF8574_read":
			$Result = $this->i2cReadByte(intval($data->DeviceIdent));
			break;
		// cmd 60 I2CWB h bv – Byte auf Handle schreiben (ohne Register)
		case "i2c_write_byte_onhandle": case "i2c_MCP3424_write": case "i2c_BH1750_write":
			$Result = $this->i2cWriteByteH(intval($data->DeviceIdent), $data->Value);
			break;
		// cmd 68 I2CWI h r bvs – Block-Bytes schreiben (Parameter-Array)
		case "i2c_PCF8583_write_array": case "i2c_MCP23017_write": case "i2c_DS3231_write":
			$Result = $this->i2cWriteBlock(intval($data->DeviceIdent), $data->Register, unserialize($data->Parameter));
			break;
		// cmd 57 I2CWD h bvs – Device-Bytes schreiben
		case "i2c_EZOCircuit_write":
			$Result = $this->i2cWriteDevice(intval($data->DeviceIdent), unserialize($data->Parameter));
			break;
		// PCA9685: 3 Word-Reads an Register +0/+4/+8
		case "i2c_PCA9685_Read_Group":
			$h = $this->i2cGetHandle(intval($data->DeviceIdent));
			if ($h >= 0) {
				$Result = serialize([
					$this->CommandClientSocket(pack("L*", 63, $h, $data->Register,     0), 16),
					$this->CommandClientSocket(pack("L*", 63, $h, $data->Register + 4, 0), 16),
					$this->CommandClientSocket(pack("L*", 63, $h, $data->Register + 8, 0), 16),
				]);
			}
			break;
		// PCA9685: Block-Write 12 Werte (RGBW)
		case "i2c_PCA9685_Write_Channel_RGBW":
			$h = $this->i2cGetHandle(intval($data->DeviceIdent));
			if ($h >= 0) {
				$Result = $this->CommandClientSocket(pack("LLLLC*", 68, $h, $data->Register, 12,
					$data->Value_1, $data->Value_2, $data->Value_3, $data->Value_4,
					$data->Value_5, $data->Value_6, $data->Value_7, $data->Value_8,
					$data->Value_9, $data->Value_10,$data->Value_11,$data->Value_12), 16);
			}
			break;
		// PCA9685: Block-Write 4 Werte (White)
		case "i2c_PCA9685_Write_Channel_White":
			$h = $this->i2cGetHandle(intval($data->DeviceIdent));
			if ($h >= 0) {
				$Result = $this->CommandClientSocket(pack("LLLLC*", 68, $h, $data->Register, 4,
					$data->Value_1, $data->Value_2, $data->Value_3, $data->Value_4), 16);
			}
			break;
		// Compound-Write: 4 aufeinanderfolgende Register (cmd 62)
		case "i2c_write_4_byte":
			$h = $this->i2cGetHandle(intval($data->DeviceIdent));
			if ($h >= 0) {
				$msg = '';
				foreach ([$data->Value_1, $data->Value_2, $data->Value_3, $data->Value_4] as $k => $v)
					$msg .= pack("L*", 62, $h, $data->Register + $k, 4, $v);
				$Result = $this->CommandClientSocket($msg, 64);
			}
			break;
		// Compound-Write: 12 aufeinanderfolgende Register (cmd 62)
		case "i2c_write_12_byte":
			$h = $this->i2cGetHandle(intval($data->DeviceIdent));
			if ($h >= 0) {
				$vals = [$data->Value_1,$data->Value_2,$data->Value_3,$data->Value_4,
				         $data->Value_5,$data->Value_6,$data->Value_7,$data->Value_8,
				         $data->Value_9,$data->Value_10,$data->Value_11,$data->Value_12];
				$msg = '';
				foreach ($vals as $k => $v) $msg .= pack("L*", 62, $h, $data->Register + $k, 4, $v);
				$Result = $this->CommandClientSocket($msg, 192);
			}
			break;

		// --- Serielle Kommunikation ---
		case "get_handle_serial":
			if ($this->GetBuffer("ModuleReady") == 1) {
				if ($this->GetBuffer("Serial_Configured") == 0) {
					$PinUsed = unserialize($this->GetBuffer("PinUsed"));
					if ($this->GetBuffer("Default_Serial_Bus") == 0)
						$this->CommandClientSocket(pack("L*", 0, 14, 4, 0) . pack("L*", 0, 15, 4, 0), 32); // Alt0
					else
						$this->CommandClientSocket(pack("L*", 0, 14, 2, 0) . pack("L*", 0, 15, 2, 0), 32); // Alt5 (RPi3)
					$PinUsed[14] = $PinUsed[15] = 99999;
					$this->SetBuffer("PinUsed", serialize($PinUsed));
					$this->SetBuffer("Serial_Configured", 1);
					$this->SendDebug("Get Serial Handle", "Mode der GPIO fuer Seriellen Bus gesetzt", 0);
					$Script         = "tag 999 wait p0 mils p1 evt p2";
					$SerialScriptID = $this->CommandClientSocket(pack("L*", 38, 0, 0, strlen($Script)) . $Script, 16);
					$this->SetBuffer("SerialScriptID", $SerialScriptID);
					$this->SendDebug("Serial Skript ID", "SerialScriptID: " . (int)$SerialScriptID, 0);
					if ($this->GetBuffer("SerialScriptID") >= 0)
						$this->StartProc((int)$SerialScriptID, serialize([32768, 50, 1]));
					$Handle = $this->GetBuffer("Handle");
					if ($Handle >= 0) $this->CommandClientSocket(pack("L*", 115, $Handle, 1, 0), 16);
				}
				$SerialHandle = $this->CommandClientSocket(pack("L*", 76, $data->Baud, 0, strlen($data->Device)) . $data->Device, 16);
				$oldHandle    = (int)$this->GetBuffer("Serial_Handle");
				if ($oldHandle >= 0) {
					$this->CommandClientSocket(pack("L*", 77, $oldHandle, 0, 0), 16);
					$this->SendDebug("Serial_Handle", "Alter Handle " . $oldHandle . " geschlossen", 0);
				}
				if ($SerialHandle !== null && (int)$SerialHandle >= 0) {
					$this->SetBuffer("Serial_Handle", (int)$SerialHandle);
					$this->SendDebug("Serial_Handle", (int)$SerialHandle, 0);
					$this->RegisterMessage($data->InstanceID, IM_CONNECT);
					$this->RegisterMessage($data->InstanceID, IM_DISCONNECT);
					$Result = true;
				} else {
					$this->SetBuffer("Serial_Handle", -1);
					$errCode = ($SerialHandle !== null) ? abs((int)$SerialHandle) : 0;
					$errText = ($SerialHandle !== null) ? $this->GetErrorText($errCode) : "Socket-Timeout / keine Antwort";
					$this->SendDebug("Serial_Handle", "Fehler beim Oeffnen: " . $errText, 0);
					IPS_LogMessage("IPS2GPIO Serial", "Fehler beim Oeffnen von " . $data->Device . ": " . $errText);
					$Result = false;
				}
			} else {
				$Result = false;
			}
			break;
		case "write_bytes_serial":
			if ((int)$this->GetBuffer("Serial_Handle") < 0) break;
			$Command = mb_convert_encoding($data->Command, 'ISO-8859-1', 'UTF-8');
			$this->CommandClientSocket(pack("L*", 81, $this->GetBuffer("Serial_Handle"), 0, strlen($Command)) . $Command, 16);
			IPS_Sleep(75);
			$this->CheckSerial();
			break;
		case "open_bb_serial_display":
			if ($this->GetBuffer("ModuleReady") == 1) {
				if ($this->GetBuffer("Serial_Display_Configured") == 0) {
					$PinUsed = unserialize($this->GetBuffer("PinUsed"));
					$this->CommandClientSocket(pack("L*", 0, (int)$data->Pin_RxD, 0, 0), 16);
					$PinUsed[(int)$data->Pin_RxD] = $data->InstanceID;
					$this->SetBuffer("Serial_Display_RxD", (int)$data->Pin_RxD);
					$this->CommandClientSocket(pack("L*", 0, (int)$data->Pin_TxD, 1, 0), 16);
					$PinUsed[(int)$data->Pin_TxD] = $data->InstanceID;
					$this->SetBuffer("PinUsed", serialize($PinUsed));
					$this->CommandClientSocket(pack("L*", 44, (int)$data->Pin_RxD, 0, 0), 16);
					$this->CommandClientSocket(pack("L*", 42, (int)$data->Pin_RxD, $data->Baud, 4, 8), 16);
					$Handle = $this->GetBuffer("Handle");
					if ($Handle >= 0)
						$this->CommandClientSocket(pack("L*", 115, $Handle, (1 << (int)$data->Pin_RxD), 0), 16); // pow() gibt Float; 1<< gibt int auf 32+64 Bit
					$this->SetBuffer("Serial_Display_Configured", 1);
					$this->SendDebug("Display", "Mode der GPIO fuer Seriellen Bus gesetzt", 0);
				}
				$this->RegisterMessage($data->InstanceID, IM_CONNECT);
				$this->RegisterMessage($data->InstanceID, IM_DISCONNECT);
				$Result = true;
			} else {
				$Result = false;
			}
			break;
		case "write_bb_bytes_serial":
			$cmd = $data->Command;
			$this->SendDebug("Serielle Sendung", "GPIO: " . $data->Pin_TxD . " Baud: " . $data->Baud . " Text: " . $cmd, 0);
			if ($this->sendBitBangWave($data->Pin_TxD, $data->Baud, $cmd)) {
				if ($this->GetBuffer("Serial_PTLB10VE_TxD") == $data->Pin_TxD) {
					IPS_Sleep(50);
					$this->CommandClientSocket(pack("L*", 43, $this->GetBuffer("Serial_PTLB10VE_RxD"), 8192, 0), 16 + 8192);
				}
				$Result = true;
			} else {
				$Result = false;
			}
			break;
		case "write_bb_bytesarray_serial":
			$cmd = pack("C*", ...unserialize($data->Command));
			$this->SendDebug("Serielle Sendung", "GPIO: " . $data->Pin_TxD . " Baud: " . $data->Baud . " Text: " . $cmd, 0);
			$Result = $this->sendBitBangWave($data->Pin_TxD, $data->Baud, $cmd);
			break;
		case "open_bb_serial_sds011":
			if ($this->GetBuffer("ModuleReady") == 1) {
				if ($this->GetBuffer("Serial_SDS011_Configured") == 0) {
					$PinUsed = unserialize($this->GetBuffer("PinUsed"));
					$this->CommandClientSocket(pack("L*", 0, (int)$data->Pin_RxD, 0, 0), 16);
					$PinUsed[(int)$data->Pin_RxD] = $data->InstanceID;
					$this->SetBuffer("Serial_SDS011_RxD", (int)$data->Pin_RxD);
					$this->CommandClientSocket(pack("L*", 0, (int)$data->Pin_TxD, 1, 0), 16);
					$this->SetBuffer("Serial_SDS011_TxD", (int)$data->Pin_TxD);
					$PinUsed[(int)$data->Pin_TxD] = $data->InstanceID;
					$this->SetBuffer("PinUsed", serialize($PinUsed));
					$this->CommandClientSocket(pack("L*", 44, (int)$data->Pin_RxD, 0, 0), 16);
					$this->CommandClientSocket(pack("L*", 42, (int)$data->Pin_RxD, $data->Baud, 4, 8), 16);
					$this->SetBuffer("Serial_SDS011_Configured", 1);
					$this->SendDebug("GPIO", "Mode der GPIO fuer Seriellen Bus gesetzt", 0);
				}
				$this->RegisterMessage($data->InstanceID, IM_CONNECT);
				$this->RegisterMessage($data->InstanceID, IM_DISCONNECT);
				$Result = true;
			} else {
				$Result = false;
			}
			break;
		case "read_bb_serial":
			$Result = $this->CommandClientSocket(pack("L*", 43, (int)$data->Pin_RxD, 8192, 0), 16 + 8192);
			break;
		case "check_bytes_serial":
			if ((int)$this->GetBuffer("Serial_Handle") >= 0)
				$Result = $this->CommandClientSocket(pack("L*", 82, $this->GetBuffer("Serial_Handle"), 0, 0), 16);
			break;

		// --- Raspberry Pi SSH ---
		case "get_RPi_connect":
			if ($data->IsArray) {
				$Result = $this->SSH_Connect_Array($data->Command);
				$this->SendDataToChildren(json_encode(["DataID" => $dataID,
					"Function" => "set_RPi_connect", "InstanceID" => $data->InstanceID,
					"CommandNumber" => $data->CommandNumber,
					"Result" => mb_convert_encoding($Result, 'UTF-8', 'ISO-8859-1'), "IsArray" => true]));
			} else {
				$Result = $this->SSH_Connect($data->Command);
				$this->SendDataToChildren(json_encode(["DataID" => $dataID,
					"Function" => "set_RPi_connect", "InstanceID" => $data->InstanceID,
					"CommandNumber" => $data->CommandNumber,
					"Result" => mb_convert_encoding($Result, 'UTF-8', 'ISO-8859-1'), "IsArray" => false]));
			}
			break;

		// --- 1-Wire (SFTP-basiert) ---
		case "get_1wire_devices":
			if ($this->GetBuffer("1Wire_Configured") == 0) {
				$PinUsed    = unserialize($this->GetBuffer("PinUsed"));
				$this->CommandClientSocket(pack("L*", 0, 4, 1, 0), 16);
				$PinUsed[4] = 99999;
				$this->SetBuffer("PinUsed", serialize($PinUsed));
				$this->SetBuffer("1Wire_Configured", 1);
				$this->SendDebug("Get Serial Handle", "Mode der GPIO fuer 1Wire gesetzt", 0);
			}
			$Result = mb_convert_encoding($this->GetOneWireDevices(), 'UTF-8', 'ISO-8859-1');
			break;
		case "get_1W_data":
			$Result = mb_convert_encoding($this->SSH_Connect_Array($data->Command), 'UTF-8', 'ISO-8859-1');
			break;

		// --- 1-Wire (DS2482-basiert) ---
		case "get_OWDevices":
			if ($this->ReadPropertyBoolean("Open") && $this->GetParentStatus() == 102) {
				$j = 0;
				$this->OWSearchStart();
				$OWDeviceArray     = unserialize($this->GetBuffer("OWDeviceArray"));
				$DeviceSerialArray = [];
				if (count($OWDeviceArray, COUNT_RECURSIVE) >= 4) {
					foreach ($OWDeviceArray as $entry) {
						$FamilyCode = substr($entry[1], -2);
						if ($FamilyCode == $data->FamilyCode && $entry[2] == 0) {
							$DeviceSerialArray[$j][0] = $entry[1]; // Seriennummer
							$DeviceSerialArray[$j][1] = $entry[5]; // Adress-Teil 0
							$DeviceSerialArray[$j][2] = $entry[6]; // Adress-Teil 1
							$j++;
						}
					}
				}
				$this->SendDataToChildren(json_encode(["DataID" => $dataID,
					"Function" => "set_OWDevices", "InstanceID" => $data->InstanceID,
					"Result" => serialize($DeviceSerialArray)]));
				$Result = true;
			} else {
				$Result = false;
			}
			break;
		case "set_OWDevices":
			if ($this->GetBuffer("ModuleReady") == 1) {
				$OWInstanceArray = [];
				$existingBuffer  = $this->GetBuffer("OWInstanceArray");
				if ($existingBuffer !== "") {
					$decoded = unserialize($existingBuffer);
					if (is_array($decoded)) $OWInstanceArray = $decoded;
				}
				$OWInstanceArray[$data->InstanceID]["DeviceSerial"] = $data->DeviceSerial;
				$OWDeviceArray = unserialize($this->GetBuffer("OWDeviceArray"));
				if (count($OWDeviceArray, COUNT_RECURSIVE) >= 4) {
					foreach ($OWDeviceArray as $entry) {
						if ($entry[1] == $data->DeviceSerial) {
							$OWInstanceArray[$data->InstanceID]["Address_0"] = $entry[5];
							$OWInstanceArray[$data->InstanceID]["Address_1"] = $entry[6];
						}
					}
				} else {
					$OWInstanceArray[$data->InstanceID]["Address_0"] = 0;
					$OWInstanceArray[$data->InstanceID]["Address_1"] = 0;
				}
				$OWInstanceArray[$data->InstanceID]["Status"] = "Angemeldet";
				$this->SetBuffer("OWInstanceArray", serialize($OWInstanceArray));
				$this->RegisterMessage($data->InstanceID, IM_CONNECT);
				$this->RegisterMessage($data->InstanceID, IM_DISCONNECT);
			}
			break;
		// DS18S20/DS18B20: gemeinsame Helfer-Methode owReadTemperature()
		case "get_DS18S20Temperature":
			$this->owReadTemperature($data, "OWRead_18S20_Temperature", "set_DS18S20Temperature", $dataID, $dataID);
			break;
		case "get_DS18B20Temperature":
			$this->owReadTemperature($data, "OWRead_18B20_Temperature", "set_DS18B20Temperature", $dataID, "{573FFA75-2A0C-48AC-BF45-FCB01D6BF910}");
			break;
		case "set_DS18B20Setup":
			if ($this->ReadPropertyBoolean("Open") && $this->GetParentStatus() == 102) {
				if (IPS_SemaphoreEnter("OW", 3000)) {
					$this->SetBuffer("owDeviceAddress_0", $data->DeviceAddress_0);
					$this->SetBuffer("owDeviceAddress_1", $data->DeviceAddress_1);
					if ($this->OWReset()) {
						$this->OWSelect(); $this->OWWriteByte(78); $this->OWWriteByte(0);
						$this->OWWriteByte(0); $this->OWWriteByte($data->Resolution);
					}
					IPS_SemaphoreLeave("OW");
				} else { $this->SendDebug("DS18B20Setup", "Semaphore Abbruch", 0); }
			}
			break;
		case "get_DS2413State":
			if ($this->ReadPropertyBoolean("Open") && $this->GetParentStatus() == 102) {
				if (IPS_SemaphoreEnter("OW", 2000)) {
					$this->SetBuffer("owDeviceAddress_0", $data->DeviceAddress_0);
					$this->SetBuffer("owDeviceAddress_1", $data->DeviceAddress_1);
					if ($this->OWVerify()) {
						if ($this->OWReset()) {
							$this->OWSelect(); $this->OWWriteByte(0xF5); // PIO ACCESS READ
							$Result = $this->OWRead_2413_State();
							$this->SendDataToChildren(json_encode(["DataID" => $dataID,
								"Function" => "set_DS2413State", "InstanceID" => $data->InstanceID, "Result" => $Result]));
						}
					} else {
						$this->SendDebug("get_DS2413State", "OWVerify: Device nicht gefunden!", 0);
						$this->SendDataToChildren(json_encode(["DataID" => $dataID,
							"Function" => "status", "InstanceID" => $data->InstanceID, "Status" => 201]));
					}
					IPS_SemaphoreLeave("OW");
				} else { $this->SendDebug("DS2413State", "Semaphore Abbruch", 0); }
			}
			break;
		case "set_DS2413Setup":
			if ($this->ReadPropertyBoolean("Open") && $this->GetParentStatus() == 102) {
				if (IPS_SemaphoreEnter("OW", 3000)) {
					$this->SetBuffer("owDeviceAddress_0", $data->DeviceAddress_0);
					$this->SetBuffer("owDeviceAddress_1", $data->DeviceAddress_1);
					if ($this->OWReset()) {
						$this->OWSelect(); $this->OWWriteByte(0x5A); // PIO ACCESS WRITE
						$this->OWWriteByte($data->Setup); $this->OWWriteByte($data->Setup ^ 0xFF);
					}
					IPS_SemaphoreLeave("OW");
				} else { $this->SendDebug("DS2413Setup", "Semaphore Abbruch", 0); }
			}
			break;
		case "get_DS2438Measurement":
			if ($this->ReadPropertyBoolean("Open") && $this->GetParentStatus() == 102) {
				if (IPS_SemaphoreEnter("OW", 3000)) {
					$this->SetBuffer("owDeviceAddress_0", $data->DeviceAddress_0);
					$this->SetBuffer("owDeviceAddress_1", $data->DeviceAddress_1);
					if ($this->OWVerify()) {
						// Schritt 1: VAD ermitteln
						if ($this->OWReset()) {
							$this->OWSelect();
							$this->OWWriteByte(0x4E); $this->OWWriteByte(0x00); $this->OWWriteByte(0x07);
							if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0xB4); IPS_Sleep(10);
								if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0xB8); $this->OWWriteByte(0x00); IPS_Sleep(10);
									if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0xBE); $this->OWWriteByte(0x00);
										list($Celsius, $Voltage_VAD, $Current) = $this->OWRead_2438();
									}
								}
							}
						}
						// Schritt 2: VDD + Temperatur ermitteln
						if ($this->OWReset()) {
							$this->OWSelect();
							$this->OWWriteByte(0x4E); $this->OWWriteByte(0x00); $this->OWWriteByte(0x0F);
							if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0x44); IPS_Sleep(10);
								if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0xB4); IPS_Sleep(10);
									if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0xB8); $this->OWWriteByte(0x00); IPS_Sleep(10);
										if ($this->OWReset()) { $this->OWSelect(); $this->OWWriteByte(0xBE); $this->OWWriteByte(0x00);
											list($Celsius, $Voltage_VDD, $Current) = $this->OWRead_2438();
											$this->SendDataToChildren(json_encode(["DataID" => $dataID,
												"Function" => "set_DS2438", "InstanceID" => $data->InstanceID,
												"Temperature" => $Celsius, "Voltage_VDD" => $Voltage_VDD,
												"Voltage_VAD" => $Voltage_VAD, "Current" => $Current]));
										}
									}
								}
							}
						}
					} else {
						$this->SendDebug("get_DS2438Measurement", "OWVerify: Device nicht gefunden!", 0);
						$this->SendDataToChildren(json_encode(["DataID" => $dataID,
							"Function" => "status", "InstanceID" => $data->InstanceID, "Status" => 201]));
					}
					IPS_SemaphoreLeave("OW");
				} else { $this->SendDebug("DS2438Measurement", "Semaphore Abbruch", 0); }
			}
			break;
		}
		return $Result;
	}

	// =========================================================
	// ReceiveData, RequestAction
	// =========================================================

	public function ReceiveData($JSONString) {
		$Data         = json_decode($JSONString);
		$Message      = mb_convert_encoding($Data->Buffer, 'ISO-8859-1', 'UTF-8');
		$MessageLen   = strlen($Message);
		// 32-Bit-Kompatibilitaet: unpackLE32s() normalisiert uint32 -> int32 auf beiden Architekturen
		$MessageArray = $this->unpackLE32s($Message);
		$GPSDataRead  = false;
		$this->SendDebug("Datenanalyse", "Laenge: " . $MessageLen . " Anzahl: " . count($MessageArray), 0);

		for ($i = 1; $i <= count($MessageArray); $i++) {
			$Command        = $MessageArray[$i];
			$SeqNo          = $MessageArray[$i] & 65535;
			$Flags          = ($MessageArray[$i] >> 16) & 0xFFFF; // Maske schuetzt vor neg. Vorzeichen-Extension
			$Event          = (int)boolval($Flags & 128);
			$EventNumber    = $Flags & 31;
			$KeepAlive      = (int)boolval($Flags & 64);
			$WatchDog       = (int)boolval($Flags & 32);
			$WatchDogNumber = $Flags & 31;
			if (isset($MessageArray[$i + 1])) $Tick  = $MessageArray[$i + 1];
			if (isset($MessageArray[$i + 2])) $Level = $MessageArray[$i + 2];

			if ($Command == 99) {
				if (array_key_exists($i + 3, $MessageArray)) {
					if ($MessageArray[$i] == 99 && $MessageArray[$i + 1] == 0 && $MessageArray[$i + 2] == 0) {
						$this->SendDebug("Datenanalyse", "Kommando: " . $MessageArray[$i], 0);
						$this->ClientResponse(pack("L*", $MessageArray[$i], $MessageArray[$i + 1], $MessageArray[$i + 2], $MessageArray[$i + 3]));
						$this->setPigpioStatus(true);
						$i += 3;
					}
				}
			} elseif ($KeepAlive == 1) {
				$this->SendDebug("Datenanalyse", "KeepAlive - SeqNo: " . $SeqNo, 0);
				SetValueInteger($this->GetIDForIdent("LastKeepAlive"), time());
				$this->setPigpioStatus(true);
				$i += 2;
			} elseif ($WatchDog == 1) {
				$this->SendDebug("Datenanalyse", "WatchDog-Nummer: " . $WatchDogNumber . " - SeqNo: " . $SeqNo, 0);
				$this->setPigpioStatus(true);
				$i += 2;
			} elseif ($Event == 1) {
				$this->SendDebug("Datenanalyse", "Event-Nummer: " . $EventNumber . " - SeqNo: " . $SeqNo, 0);
				if ($EventNumber == $this->GetBuffer("Serial_Display_RxD"))
					$this->CommandClientSocket(pack("L*", 43, $this->GetBuffer("Serial_Display_RxD"), 8192, 0), 16 + 8192);
				elseif ($EventNumber == $this->GetBuffer("Serial_GPS_RxD")) {
					if (!$GPSDataRead) {
						$this->CommandClientSocket(pack("L*", 43, $this->GetBuffer("Serial_GPS_RxD"), 8192, 0), 16 + 8192);
						$GPSDataRead = true;
					}
				} elseif ($EventNumber == $this->GetBuffer("Serial_PTLB10VE_RxD"))
					$this->CommandClientSocket(pack("L*", 43, $this->GetBuffer("Serial_PTLB10VE_RxD"), 8192, 0), 16 + 8192);
				elseif ($EventNumber == $this->GetBuffer("Serial_SDS011_RxD"))
					$this->CommandClientSocket(pack("L*", 43, $this->GetBuffer("Serial_SDS011_RxD"), 8192, 0), 16 + 8192);
				$this->setPigpioStatus(true);
				$i += 2;
			} else {
				$PinNotify     = unserialize($this->GetBuffer("PinNotify"));
				$NotifyBitmask = intval($this->GetBuffer("NotifyBitmask"));
				$LastNotify    = intval($this->GetBuffer("LastNotify"));
				$Level         = $Level & $NotifyBitmask;
				for ($j = 0; $j < count($PinNotify); $j++) {
					$Bitvalue     = boolval($Level & (1 << $PinNotify[$j]));
					$LastBitvalue = boolval($LastNotify & (1 << $PinNotify[$j]));
					$this->SendDebug("Datenanalyse", "Interrupt - Bit " . $PinNotify[$j] . " Aktuell: " . (int)$Bitvalue . " Letzt: " . (int)$LastBitvalue . " SeqNo: " . $SeqNo, 0);
					if ($LastNotify == -1 || $LastBitvalue != $Bitvalue) {
						$this->SendDataToChildren(json_encode(["DataID" => "{8D44CA24-3B35-4918-9CBD-85A28C0C8917}",
							"Function" => "notify", "Pin" => $PinNotify[$j], "Value" => $Bitvalue, "Timestamp" => $Tick]));
					}
				}
				$this->SetBuffer("LastNotify", $Level);
				$this->setPigpioStatus(true);
				$i += 2;
			}
		}
	}

	public function RequestAction($Ident, $Value) {
		switch ($Ident) {
			case "Open":
				$this->setStatusSafe($Value ? 102 : 104);
				if ($Value) $this->ConnectionTest();
				SetValue($this->GetIDForIdent($Ident), $Value);
				break;
			default:
				throw new Exception("Invalid Ident");
		}
	}

	// =========================================================
	// Socket-Kommunikation
	// =========================================================

	private function ClientSocket(string $message) {
		if ($this->ReadPropertyBoolean("Open") && $this->GetParentStatus() == 102) {
			$this->SendDataToParent(json_encode(["DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}",
				"Buffer" => mb_convert_encoding($message, 'UTF-8', 'ISO-8859-1')]));
		}
	}

	private function CommandClientSocket(string $message, $ResponseLen = 16) {
		$Result = -999;
		$buf    = 0;
		if (!$this->ReadPropertyBoolean("Open") || $this->GetParentStatus() != 102) return $Result;

		if (IPS_SemaphoreEnter("ClientSocket", 3000)) {
			if (!$this->Socket) {
				if (!($this->Socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
					IPS_SemaphoreLeave("ClientSocket");
					// Fix: return $Result statt return; – null-Rueckgabe verursacht Folgefehler
					return $Result;
				}
				socket_set_option($this->Socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 0, "usec" => 150000]);
				if (!socket_connect($this->Socket, $this->ReadPropertyString("IPAddress"), 8888)) {
					$errorcode = socket_last_error();
					$errormsg  = socket_strerror($errorcode);
					IPS_LogMessage("IPS2GPIO Socket", "Fehler beim Verbindungsaufbau " . $errorcode . " " . $errormsg);
					$this->SendDebug("CommandClientSocket", "Fehler Verbindungsaufbau " . $errorcode . " " . $errormsg, 0);
					$this->ClientSocket(pack("L*", 99, 0, 0, 0));
					$this->setStatusSafe(200);
					IPS_SemaphoreLeave("ClientSocket");
					return $Result; // Fix: return $Result statt return;
				}
			}
			if (!@socket_send($this->Socket, $message, strlen($message), 0)) {
				$errorcode = socket_last_error();
				$errormsg  = socket_strerror($errorcode);
				IPS_LogMessage("IPS2GPIO Socket", "Fehler beim Senden " . $errorcode . " " . $errormsg);
				$this->SendDebug("CommandClientSocket", "Fehler Senden " . $errorcode . " " . $errormsg, 0);
				IPS_SemaphoreLeave("ClientSocket");
				return $Result; // Fix: return $Result statt return;
			}
			$MessageCommand = unpack("L*", $message);
			if ($MessageCommand[1] != 43) {
				// Blockierend empfangen (Standardbefehl)
				if (socket_recv($this->Socket, $buf, $ResponseLen, MSG_WAITALL) === false) {
					$errorcode = socket_last_error();
					$errormsg  = socket_strerror($errorcode);
					IPS_LogMessage("IPS2GPIO Socket", "Fehler beim Empfangen " . $errorcode . " " . $errormsg);
					$this->SendDebug("CommandClientSocket", "Fehler Empfangen " . $errorcode . " " . $errormsg, 0);
					IPS_SemaphoreLeave("ClientSocket");
					if ($errorcode == 11) $this->PIGPIOD_Restart();
					return $Result; // Fix: return $Result statt return;
				}
			} else {
				// Nicht-blockierend empfangen (Befehl 43: SLR – Serial Read)
				// Fix: $ResponseLen statt hartcodiertem 16+1024 – kein Datenverlust bei >1024 Byte
				if (socket_recv($this->Socket, $buf, $ResponseLen, MSG_DONTWAIT) === false) {
					$errorcode = socket_last_error();
					$errormsg  = socket_strerror($errorcode);
					IPS_LogMessage("IPS2GPIO Socket", "Fehler beim Empfangen " . $errorcode . " " . $errormsg);
					$this->SendDebug("CommandClientSocket", "Fehler Empfangen " . $errorcode . " " . $errormsg, 0);
					IPS_SemaphoreLeave("ClientSocket");
					if ($errorcode == 11) $this->PIGPIOD_Restart();
					return $Result; // Fix: return $Result statt return;
				}
			}
			IPS_SemaphoreLeave("ClientSocket");
		}

		// Antwort auswerten
		$CmdVarLen    = [43, 56, 67, 70, 73, 75, 80, 88, 91, 92, 106, 109];
		$MessageArray = unpack("L*", $buf);
		if (is_array($MessageArray) && isset($MessageArray[1])) {
			$Command = $MessageArray[1];
			if (in_array($Command, $CmdVarLen)) {
				$Result = $this->ClientResponse($buf);
			// Fix: Modulo statt Division+intval – klarer und korrekt
			} elseif (strlen($buf) % 16 === 0) {
				foreach (str_split($buf, 16) as $chunk) {
					$Result = $this->ClientResponse($chunk);
				}
			}
		} else {
			IPS_LogMessage("IPS2GPIO ReceiveData", strlen($buf) . " Zeichen - nicht differenzierbar!");
		}
		return $Result;
	}

	private function ClientResponse(string $Message) {
		// 32-Bit-Kompatibilitaet: unpackLE32s() liefert int32 statt Float fuer Werte > 0x7FFFFFFF
		$response = $this->unpackLE32s($Message);
		$Result   = $response[4];
		$dataID   = "{8D44CA24-3B35-4918-9CBD-85A28C0C8917}";
		switch ($response[1]) {
			case "0":
				if ($response[4] != 0) IPS_LogMessage("IPS2GPIO Set Mode", "Pin: " . $response[2] . " Wert: " . $response[3] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "2":
				if ($response[4] != 0) IPS_LogMessage("IPS2GPIO Set Pull-up/Down", "Pin: " . $response[2] . " Wert: " . $response[3] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "3":
				if ($response[4] < 0) IPS_LogMessage("IPS2GPIO Read", "Pin: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "4":
				if ($response[4] == 0) { $Result = true; }
				else { $this->SendDebug("ClientResponse", "Write Pin: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Write", "Pin: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4]))); $Result = false; }
				break;
			case "5":
				if ($response[4] == 0) {
					$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "result", "Pin" => $response[2], "Value" => $response[3]]));
					$Result = true;
				} else { IPS_LogMessage("IPS2GPIO PWM", "Pin: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4]))); $Result = false; }
				break;
			case "8":
				if ($response[4] == 0) { $Result = true; $this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "result", "Pin" => $response[2], "Value" => $response[3]])); }
				else { $Result = false; IPS_LogMessage("IPS2GPIO PWM", "Pin: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "9":
				if ($response[4] >= 0) $this->SendDebug("WatchDog", "gesetzt", 0);
				else { IPS_LogMessage("IPS2GPIO WatchDog", "Fehler: " . $this->GetErrorText(abs($response[4]))); $this->SendDebug("WatchDog", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); }
				break;
			case "17":
				$Model[0] = [2, 3]; $Model[1] = [4, 5, 6, 13, 14, 15];
				$Typ[0]   = [0, 1, 4 => 4, 7 => 7, 8, 9, 10, 11, 14 => 14, 15, 17 => 17, 18, 21 => 21, 22, 23, 24, 25];
				$Typ[1]   = [2 => 2, 3, 4, 7 => 7, 8, 9, 10, 11, 14 => 14, 15, 17 => 17, 18, 22 => 22, 23, 24, 25, 27 => 27];
				$Typ[2]   = [2 => 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27];
				$this->SetBuffer("HardwareRev", $response[4]);
				SetValueString($this->GetIDForIdent("Hardware"), $this->GetHardware($response[4]));
				if (in_array($response[4], $Model[0])) {
					$this->SetBuffer("PinPossible", serialize($Typ[0])); $this->SetBuffer("PinI2C", serialize([0, 1]));
					$this->SendDebug("Hardwareermittlung", "Raspberry Pi Typ 0", 0);
				} elseif (in_array($response[4], $Model[1])) {
					$this->SetBuffer("PinPossible", serialize($Typ[1])); $this->SetBuffer("PinI2C", serialize([2, 3]));
					$this->SendDebug("Hardwareermittlung", "Raspberry Pi Typ 1", 0);
				} elseif ($response[4] >= 16) {
					$this->SetBuffer("PinPossible", serialize($Typ[2])); $this->SetBuffer("PinI2C", serialize([2, 3]));
					$this->SendDebug("Hardwareermittlung", "Raspberry Pi Typ 2", 0);
				} else {
					IPS_LogMessage("IPS2GPIO Hardwareermittlung", "nicht erfolgreich! Fehler: " . $this->GetErrorText(abs($response[4])));
					$this->SendDebug("Hardwareermittlung", "nicht erfolgreich! Fehler: " . $this->GetErrorText(abs($response[4])), 0);
				}
				break;
			case "19": $this->SendDebug("Notify", "gestartet", 0); break;
			case "21": $this->SendDebug("Notify", "gestoppt",  0); break;
			case "26":
				if ($response[4] >= 0) {
					SetValueInteger($this->GetIDForIdent("SoftwareVersion"), $response[4]);
					if ($response[4] < 79) { IPS_LogMessage("IPS2GPIO PIGPIO Software Version", "Bitte neuste PIGPIO-Software installieren!"); $this->SendDebug("PIGPIO Version", "PIGPIO-Software veraltet", 0); }
					else $this->SendDebug("PIGPIO Version", "PIGPIO-Software ist aktuell", 0);
				} else IPS_LogMessage("IPS2GPIO PIGPIO Software Version", "Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "27": $this->SendDebug("Waveforms", "geloescht", 0); break;
			case "28":
				if ($response[4] >= 0) { $this->SendDebug("Pulse", "Anzahl: " . $response[4], 0); $Result = $response[4]; }
				else { $Result = -1; $this->SendDebug("Pulse", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); }
				break;
			case "29":
				if ($response[4] >= 0) { $this->SendDebug("Bit Bang Serial", "Gesendet: " . $response[4], 0); $Result = true; }
				else { $this->SendDebug("Bit Bang Serial", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO", "Bit Bang Serial Fehler: " . $this->GetErrorText(abs($response[4]))); $Result = false; }
				break;
			case "37": $Result = ($response[4] >= 0); break;
			case "38":
				if ($response[4] >= 0) { $Result = $response[4]; $this->SendDebug("Skriptsendung", "Skript-ID: " . (int)$Result, 0); }
				else { $Result = -1; $this->SendDebug("Skript", "Registrierung Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO", "Skriptregistrierung Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "40":
				if ($response[4] >= 0) $Result = $response[4];
				else { $Result = -1; $this->SendDebug("Skript", "Start Fehler: " . $this->GetErrorText(abs($response[4])), 0); }
				break;
			case "42":
				if ($response[4] >= 0) $Result = true;
				else { $Result = false; $this->SendDebug("Serial Bit Bang", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO", "Serial Bit Bang Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "43":
				if ($response[4] >= 0) {
					$Result = mb_convert_encoding(substr($Message, -($response[4])), 'UTF-8', 'ISO-8859-1');
					$this->SendDebug("SLR", "Serielle-Daten: " . strlen($Result), 0);
					if ($response[2] == $this->GetBuffer("Serial_GPS_RxD"))
						$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "set_serial_gps_data", "Value" => $Result]));
					elseif ($response[2] == $this->GetBuffer("Serial_Display_RxD"))
						$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "set_serial_data", "Value" => $Result]));
					elseif ($response[2] == $this->GetBuffer("Serial_PTLB10VE_RxD"))
						$Result = substr($Message, -($response[4]));
					elseif ($response[2] == $this->GetBuffer("Serial_SDS011_RxD"))
						$Result = substr($Message, -($response[4]));
				} else { $Result = -1; $this->SendDebug("Serielle Daten", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Serielle Daten", "Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "44": $Result = ($response[4] >= 0); break;
			case "49":
				if ($response[4] >= 0) $Result = $response[4];
				else { $this->SendDebug("Waveform", "erstellt, Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Waveform", "erstellt, Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "50":
				if ($response[4] >= 0) $Result = $response[4];
				else { $this->SendDebug("Waveform", "geloescht, Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Waveform", "geloescht, Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "51":
				if ($response[4] >= 0) $Result = $response[4];
				else { $this->SendDebug("Waveform", "gesendet, Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Waveform", "gesendet, Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "54":
				// Handle-Speicherung erfolgt in set_used_i2c mit LOGISCHEM Bus (DeviceBus<<7+Adr.)
				// PIGPIO kennt nur physikalische Busse; kein Update hier, da Kollision moeglich.
				if ($response[4] < 0) IPS_LogMessage("IPS2GPIO I2C Handle", "Fehler: " . $this->GetErrorText(abs($response[4])) . " fuer Device " . $response[3]);
				break;
			case "55": break; // I2C Close Handle
			case "56":
				if ($response[4] >= 0) $Result = serialize(unpack("C*", substr($Message, -($response[4]))));
				else IPS_LogMessage("IPS2GPIO I2C Read Bytes", "Handle: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "57": $Result = ($response[4] >= 0); break;
			case "59":
				if ($response[4] < 0 && $this->GetBuffer("I2CSearch") == 0) IPS_LogMessage("IPS2GPIO I2C Read Byte Handle", "Handle: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "60":
				if ($response[4] >= 0) { $Result = true; $this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "set_i2c_data", "DeviceIdent" => $this->GetI2C_HandleDevice($response[2]), "Register" => $response[3], "Value" => $response[4]])); }
				else { $Result = false; IPS_LogMessage("IPS2GPIO I2C Write Byte Handle", "Handle: " . $response[2] . " Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "61":
				if ($response[4] < 0 && $this->GetBuffer("I2CSearch") == 0) IPS_LogMessage("IPS2GPIO I2C Read Byte", "Handle: " . $response[2] . " Register: " . $response[3] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "62":
				if ($response[4] >= 0) $Result = true;
				else { $Result = false; IPS_LogMessage("IPS2GPIO I2C Write Byte", "Handle: " . $response[2] . " Register: " . $response[3] . " Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "63":
				if ($response[4] >= 0) $this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "set_i2c_data", "DeviceIdent" => $this->GetI2C_HandleDevice($response[2]), "Register" => $response[3], "Value" => $response[4]]));
				else IPS_LogMessage("IPS2GPIO I2C Read Word", "Handle: " . $response[2] . " Register: " . $response[3] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "67":
				if ($response[4] >= 0) {
					$Result = serialize(unpack("C*", substr($Message, -($response[4]))));
					$this->SendDataToChildren(json_encode(["DataID" => $dataID, "Function" => "set_i2c_byte_block",
						"DeviceIdent" => $this->GetI2C_HandleDevice($response[2]), "Register" => $response[3],
						"Count" => $response[4], "ByteArray" => $Result]));
				} else IPS_LogMessage("IPS2GPIO I2C Read Block Byte", "Handle: " . $response[2] . " Register: " . $response[3] . " Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "68": $Result = ($response[4] >= 0); break;
			case "76": if ($response[4] < 0) IPS_LogMessage("IPS2GPIO I2C Get Serial Handle", "Fehler: " . $this->GetErrorText(abs($response[4]))); break;
			case "77": if ($response[4] < 0) IPS_LogMessage("IPS2GPIO Serial Close Handle", "Fehler: " . $this->GetErrorText(abs($response[4]))); break;
			case "80":
				if ($response[4] > 0) $Result = mb_convert_encoding(substr($Message, -($response[4])), 'UTF-8', 'ISO-8859-1');
				elseif ($response[4] < 0) IPS_LogMessage("IPS2GPIO Serial Read", "Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "81": if ($response[4] < 0) IPS_LogMessage("IPS2GPIO Serial Write", "Fehler: " . $this->GetErrorText(abs($response[4]))); break;
			case "82": if ($response[4] < 0) IPS_LogMessage("IPS2GPIO Check Bytes Serial", "Fehler: " . $this->GetErrorText(abs($response[4]))); break;
			case "83": if ($response[4] < 0) IPS_LogMessage("IPS2GPIO PWM dutycycle", "Fehler: " . $this->GetErrorText(abs($response[4]))); break;
			case "84": if ($response[4] < 0) IPS_LogMessage("IPS2GPIO Check Servo Pulsewidth", "Fehler: " . $this->GetErrorText(abs($response[4]))); break;
			case "93":
				if ($response[4] >= 0) $this->SendDebug("WaveChain", "erfolgreich", 0);
				else IPS_LogMessage("IPS2GPIO WaveChain", "Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "97":
				if ($response[4] >= 0) $this->SendDebug("GlitchFilter", "gesetzt", 0);
				else IPS_LogMessage("IPS2GPIO GlitchFilter", "Fehler: " . $this->GetErrorText(abs($response[4])));
				break;
			case "99":
				if ($response[4] >= 0) $this->SetBuffer("Handle", $response[4]);
				else $this->ClientSocket(pack("L*", 99, 0, 0, 0));
				break;
			case "115":
				if ($response[4] >= 0) $this->SendDebug("Event Monitor", "gesetzt", 0);
				else { $this->SendDebug("Event Monitor", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Set Event Monitor", "Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
			case "116":
				if ($response[4] >= 0) $this->SendDebug("Event Monitor", "gemeldet", 0);
				else { $this->SendDebug("Event Monitor", "Fehler: " . $this->GetErrorText(abs($response[4])), 0); IPS_LogMessage("IPS2GPIO Trigger Event", "Fehler: " . $this->GetErrorText(abs($response[4]))); }
				break;
		}
		return $Result;
	}

	// =========================================================
	// Oeffentliche Methoden
	// =========================================================

	public function CheckSerial() {
		$Handle = (int)$this->GetBuffer("Serial_Handle");
		if ($Handle < 0) { $this->SendDebug("CheckSerial", "Serial_Handle ungueltig (" . $Handle . ") – Abbruch", 0); return; }
		$Result = $this->CommandClientSocket(pack("L*", 82, $Handle, 0, 0), 16);
		if ($Result > 0) {
			$Message = $this->CommandClientSocket(pack("L*", 80, $Handle, $Result, 0), 16 + $Result);
			if (is_string($Message) && $Message !== '')
				$this->SendDataToChildren(json_encode(["DataID" => "{8D44CA24-3B35-4918-9CBD-85A28C0C8917}", "Function" => "set_serial_data", "Value" => $Message]));
		}
	}

	public function PIGPIOD_Restart() {
		if (!$this->ReadPropertyBoolean("Open") || $this->GetParentStatus() != 102) return;
		$ip = $this->ReadPropertyString("IPAddress");
		$this->SetBuffer("RestartMode", "1"); // GetConfigurationForm() zeigt "Bitte warten!"
		$this->ReloadForm();
		IPS_SetProperty($this->GetParentID(), "Open", false);
		IPS_ApplyChanges($this->GetParentID());
		$this->SSH_Connect("sudo killall pigpiod");
		$this->setPigpioStatus(false);
		// Polling: warten bis Port 8888 geschlossen
		$deadline = time() + 5;
		while (time() < $deadline) {
			$sock = @fsockopen($ip, 8888, $errno, $errstr, 1);
			if ($sock === false) break;
			fclose($sock); IPS_Sleep(200);
		}
		$this->SSH_Connect($this->buildPigpiodCmd());
		// Polling: warten bis Port 8888 wieder offen
		$deadline = time() + 5;
		while (time() < $deadline) {
			$sock = @fsockopen($ip, 8888, $errno, $errstr, 1);
			if ($sock !== false) { fclose($sock); break; }
			IPS_Sleep(200);
		}
		$this->SetBuffer("OWDeviceArray",   serialize([]));
		$this->SetBuffer("OWInstanceArray", serialize([]));
		$this->SetBuffer("I2C_Handle",      serialize([]));
		$this->SetBuffer("I2C_InstanceMap", serialize([]));
		IPS_SetProperty($this->GetParentID(), "Open", true);
		IPS_ApplyChanges($this->GetParentID());
		$this->SetBuffer("RestartMode", "0"); // GetConfigurationForm() zeigt wieder "Instanz ist aktiv"
		IPS_ApplyChanges($this->InstanceID);
		$this->ReloadForm();
	}

	public function SSH_Connect(string $Command) {
		if (!$this->ReadPropertyBoolean("Open")) return "";
		if (($ssh = $this->sshLogin("SSH-Connect")) === null) return false;
		$Result = $ssh->exec($Command);
		$ssh->disconnect();
		return $Result;
	}

	public function OWSearchStart() {
		$this->SetBuffer("owLastDevice",      0);
		$this->SetBuffer("owLastDiscrepancy", 0);
		$this->SetBuffer("owDeviceAddress_0", -1); // -1 = 0xFFFFFFFF (32/64-Bit-sicher)
		$this->SetBuffer("owDeviceAddress_1", -1);
		$this->SetBuffer("OWDeviceArray", serialize([]));
		$Result = 1; $SearchNumber = 0;
		if (IPS_SemaphoreEnter("OW", 3000)) {
			while ($Result == 1) $Result = $this->OWSearch($SearchNumber++);
			IPS_SemaphoreLeave("OW");
		} else {
			$this->SendDebug("OWSearchStart", "Semaphore Abbruch", 0);
		}
		return $this->GetBuffer("OWDeviceArray");
	}

	public function SearchSpecialI2CDevices(int $DeviceAddress) {
		$Response = false;
		$this->SetBuffer("I2CSearch", 1);
		$Handle = $this->CommandClientSocket(pack("L*", 54, 1, $DeviceAddress, 4, 0), 16);
		if ($Handle >= 0) {
			$Result = $this->CommandClientSocket(pack("L*", 59, $Handle, 0, 0), 16);
			if ($Result >= 0) {
				$this->SendDebug("SearchSpecialI2CDevices", "Device auf Bus 1, Adr. " . $DeviceAddress . " gefunden", 0);
				echo "Device gefunden auf Bus: 1 Adresse: " . $DeviceAddress;
				$Response = true;
			} else {
				$this->SendDebug("SearchSpecialI2CDevices", "Device nicht gefunden: " . $this->GetErrorText(abs($Result)), 0);
				echo "Device nicht gefunden auf Bus: 1 Adresse: " . $DeviceAddress . " Ergebnis: " . $this->GetErrorText(abs($Result));
			}
			$this->CommandClientSocket(pack("L*", 55, $Handle, 0, 0), 16);
		}
		$this->SetBuffer("I2CSearch", 0);
		return $Response;
	}

	// =========================================================
	// Private Hilfsmethoden (allgemein)
	// =========================================================

	/** pigpiod-Startbefehl zusammenstellen (root/sudo + AudioDAC) */
	private function buildPigpiodCmd(): string {
		$cmd = $this->ReadPropertyBoolean("AudioDAC") ? "pigpiod -t 0 -s 10" : "pigpiod -s 10";
		return ($this->ReadPropertyString("User") !== "root") ? "sudo $cmd" : $cmd;
	}

	/** PigpioStatus-Variable sicher setzen (nur bei Aenderung) */
	private function setPigpioStatus(bool $status): void {
		$id = $this->GetIDForIdent("PigpioStatus");
		if (GetValueBoolean($id) !== $status) SetValueBoolean($id, $status);
	}

	/** Instanzstatus nur bei Aenderung setzen */
	private function setStatusSafe(int $code): void {
		if ($this->GetStatus() !== $code) $this->SetStatus($code);
	}

	/** Bit-Bang-Waveform senden: WVAG(29) + WVCRE(49) + WVTX(51) + WVDEL(50) */
	private function sendBitBangWave(int $pin, int $baud, string $cmd): bool {
		$Result = $this->CommandClientSocket(pack("L*", 29, $pin, $baud, 12 + strlen($cmd), 8, 4, 0) . $cmd, 16);
		if ($Result <= 0) return false;
		$waveID = $this->CommandClientSocket(pack("L*", 49, 0, 0, 0), 16);
		if ($waveID < 0) return false;
		$Result = $this->CommandClientSocket(pack("L*", 51, $waveID, 0, 0), 16);
		$this->CommandClientSocket(pack("L*", 50, $waveID, 0, 0), 16);
		return $Result >= 0;
	}

	/** Gemeinsame 1-Wire-Temperaturmessung fuer DS18S20/DS18B20 */
	private function owReadTemperature($data, string $readMethod, string $resultFn, string $successDataID, string $errorDataID): void {
		if (!$this->ReadPropertyBoolean("Open") || $this->GetParentStatus() != 102) return;
		if (!IPS_SemaphoreEnter("OW", 3000)) { $this->SendDebug($readMethod, "Semaphore Abbruch", 0); return; }
		$this->SetBuffer("owDeviceAddress_0", $data->DeviceAddress_0);
		$this->SetBuffer("owDeviceAddress_1", $data->DeviceAddress_1);
		if ($this->OWVerify()) {
			if ($this->OWReset()) {
				$this->OWSelect();
				$this->OWWriteByte(0x44); // Temperaturwandlung starten
				IPS_Sleep($data->Time);
				$this->SetBuffer("owDeviceAddress_0", $data->DeviceAddress_0);
				$this->SetBuffer("owDeviceAddress_1", $data->DeviceAddress_1);
				if ($this->OWReset()) {
					$this->OWSelect();
					$this->OWWriteByte(0xBE); // Scratchpad lesen
					$celsius = $this->$readMethod();
					$this->SendDataToChildren(json_encode(["DataID" => $successDataID,
						"Function" => $resultFn, "InstanceID" => $data->InstanceID, "Result" => $celsius]));
				}
			}
		} else {
			$this->SendDebug($readMethod, "OWVerify: Device nicht gefunden!", 0);
			$this->SendDataToChildren(json_encode(["DataID" => $errorDataID,
				"Function" => "status", "InstanceID" => $data->InstanceID, "Status" => 201]));
		}
		IPS_SemaphoreLeave("OW");
	}

	/**
	 * SSH-Session aufbauen – gemeinsamer Helper.
	 * Gibt die verbundene SSH2-Instanz zurueck oder null bei Fehler.
	 */
	private function sshLogin(string $logContext = 'SSH-Connect'): ?\phpseclib3\Net\SSH2 {
		(new AutoLoaderPHPSecLib('Net\SSH2'))->register();
		$ssh = new \phpseclib3\Net\SSH2($this->ReadPropertyString("IPAddress"));
		if (!@$ssh->login($this->ReadPropertyString("User"), $this->ReadPropertyString("Password"))) {
			IPS_LogMessage("IPS2GPIO $logContext", "IP " . $this->ReadPropertyString("IPAddress") . " reagiert nicht!");
			return null;
		}
		return $ssh;
	}

	/**
	 * SFTP-Session aufbauen – gemeinsamer Helper.
	 * Gibt die verbundene SFTP-Instanz zurueck oder null bei Fehler.
	 */
	private function sftpLogin(string $logContext = 'SFTP-Connect'): ?\phpseclib3\Net\SFTP {
		(new AutoLoaderPHPSecLib('Net\SFTP'))->register();
		$sftp = new \phpseclib3\Net\SFTP($this->ReadPropertyString("IPAddress"));
		if (!@$sftp->login($this->ReadPropertyString("User"), $this->ReadPropertyString("Password"))) {
			IPS_LogMessage("IPS2GPIO $logContext", "IP " . $this->ReadPropertyString("IPAddress") . " reagiert nicht!");
			return null;
		}
		return $sftp;
	}

	// =========================================================
	// Private I2C-Hilfsmethoden
	// =========================================================

	/** Handle holen + MUX-Bus setzen; gibt Handle oder -1 zurueck */
	private function i2cGetHandle(int $ident): int {
		$handle = $this->GetI2C_DeviceHandle($ident);
		if ($handle >= 0) $this->SetI2CBus($ident);
		return $handle;
	}
	/** cmd 59 I2CRB h – Byte vom Handle lesen (ohne Register) */
	private function i2cReadByte(int $ident): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 59, $h, 0, 0), 16) : -1;
	}
	/** cmd 60 I2CWB h bv – Byte auf Handle schreiben (ohne Register) */
	private function i2cWriteByteH(int $ident, int $value): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 60, $h, $value, 0), 16) : -1;
	}
	/** cmd 61 I2CRB h r – Byte aus Register lesen */
	private function i2cReadReg(int $ident, int $reg): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 61, $h, $reg, 0), 16) : -1;
	}
	/** cmd 62 I2CWB h r bv – Byte in Register schreiben */
	private function i2cWriteReg(int $ident, int $reg, int $value): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 62, $h, $reg, 4, $value), 16) : -1;
	}
	/** cmd 63 I2CRW h r – Word aus Register lesen */
	private function i2cReadWord(int $ident, int $reg): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 63, $h, $reg, 0), 16) : -1;
	}
	/** cmd 56 I2CRD h count – Bytes lesen (ohne Register) */
	private function i2cReadBytes(int $ident, int $count): string|int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 56, $h, $count, 0), 16 + $count) : -1;
	}
	/** cmd 67 I2CRI h r count – Block-Bytes lesen */
	private function i2cReadBlock(int $ident, int $reg, int $count): string|int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("L*", 67, $h, $reg, 4, $count), 16 + $count) : -1;
	}
	/** cmd 68 I2CWI h r bvs – Block-Bytes schreiben */
	private function i2cWriteBlock(int $ident, int $reg, array $bytes): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("LLLLC*", 68, $h, $reg, count($bytes), ...$bytes), 16) : -1;
	}
	/** cmd 57 I2CWD h bvs – Device-Bytes schreiben */
	private function i2cWriteDevice(int $ident, array $bytes): int {
		$h = $this->i2cGetHandle($ident);
		return ($h >= 0) ? $this->CommandClientSocket(pack("LLLLC*", 57, $h, 0, count($bytes), ...$bytes), 16) : -1;
	}

	// =========================================================
	// Weitere private Methoden
	// =========================================================

	// =========================================================
	// 32-Bit / 64-Bit Kompatibilitaet
	// =========================================================

	/**
	 * Entpackt $data als vorzeichenbehaftete 32-Bit-Little-Endian-Integer.
	 *
	 * Hintergrund: unpack("L*") / unpack("V*") interpretiert 32-Bit-Werte als
	 * "unsigned long". Auf 64-Bit-PHP passen diese (0..4294967295) in ein PHP-
	 * Integer. Auf 32-Bit-PHP (bookworm armhf) ist PHP_INT_MAX = 2147483647;
	 * Werte > 0x7FFFFFFF werden als FLOAT zurueckgegeben. Bitweise Operatoren
	 * (&, |, >>) auf Floats liefern falsche Ergebnisse (PHP castet Float vor
	 * dem Bit-Op nach int, was auf 32-Bit ueberlaueft).
	 *
	 * Diese Methode konvertiert alle Werte 0x80000000..0xFFFFFFFF zum
	 * vorzeichenbehafteten Aequivalent (wie C-cast (int32_t)), sodass
	 * Bit-Operatoren und Vergleiche (>= 0, < 0) auf beiden Architekturen
	 * identisch funktionieren.
	 *
	 * Beispiel: pigpiod-Fehlercode -71 (PI_I2C_OPEN_FAILED)
	 *   32-Bit ohne Fix: 4294967225.0 (float)  -> $h >= 0 TRUE (falsch!)
	 *   64-Bit ohne Fix: 4294967225   (int)    -> $h >= 0 TRUE (falsch!)
	 *   nach Fix:        -71          (int)    -> $h >= 0 FALSE (korrekt)
	 */
	private function unpackLE32s(string $data): array {
		// "V" = little-endian uint32 = "L" auf LE-Systemen (x86, ARM)
		$arr = unpack("V*", $data);
		foreach ($arr as &$v) {
			// 0x7FFFFFFF = 2147483647 = PHP_INT_MAX auf 32-Bit (sicherer int-Vergleich)
			// Auf 32-Bit-PHP ist $v fuer Werte > PHP_INT_MAX ein Float; der Vergleich
			// "float > int" funktioniert korrekt, weil PHP den int promoted.
			if ($v > 0x7FFFFFFF) {
				// 4294967296.0 = 2^32 als IEEE-754-double (exakt darstellbar)
				$v = (int)($v - 4294967296.0);
			}
		}
		return $arr;
	}

	// =========================================================
	// 1-Wire Adress-Hilfsmethode
	// =========================================================

	/**
	 * Gibt einen owDeviceAddress-Buffer-Wert als exakt 8-stelligen
	 * Hex-String aus – identisch auf 32-Bit- und 64-Bit-PHP.
	 *
	 * Problem: Die Buffer speichern signed 32-Bit-Integer (koennen negativ
	 * sein, z.B. 0x8D3C01F0 = -1925708304). sprintf("%X", negative_int):
	 *   32-Bit PHP: -1925708304 -> "8D3C01F0"   (korrekt, 8 Zeichen)
	 *   64-Bit PHP: -1925708304 -> "FFFFFFFF8D3C01F0" (falsch, 16 Zeichen)
	 * weil PHP auf 64-Bit die unsigned 64-Bit-Darstellung der negativen Zahl
	 * ausgibt (obere 32 Bit = 0xFFFFFFFF).
	 *
	 * Fix: pack("N", int) verpackt die unteren 32 Bit als unsigned big-endian,
	 * unabhaengig von der PHP-Integer-Breite. bin2hex() liefert immer exakt
	 * 8 Hex-Zeichen.
	 */
	private function owAddrToHex8(string $bufferValue): string {
		// pack("N") = unsigned 32-Bit big-endian; negative Werte werden
		// via Modulo-2^32 korrekt als uint32 verpackt – auf 32- und 64-Bit.
		return strtoupper(bin2hex(pack("N", (int)$bufferValue)));
	}

	private function SendProc(string $Message) {
		$Result = $this->CommandClientSocket(pack("L*", 38, 0, 0, strlen($Message)) . $Message, 16);
		if ($Result < 0) { $this->SendDebug("Skriptsendung", "Fehlgeschlagen!", 0); return -1; }
		$this->SendDebug("Skriptsendung", "Skript-ID: " . (int)$Result, 0);
		return $Result;
	}

	private function StartProc(int $ScriptID, string $Parameter) {
		$ParameterArray = unserialize($Parameter);
		$Result = $this->CommandClientSocket(
			pack("L*", 40, $ScriptID, 0, 4 * count($ParameterArray)) . pack("L*", ...$ParameterArray), 16);
		if ($Result < 0) { $this->SendDebug("Skriptstart", "Skript-ID: " . (int)$ScriptID . " – Fehlgeschlagen!", 0); return -1; }
		$StatusArray = ["wird initialisiert", "angehalten", "laeuft", "wartet", "fehlerhaft"];
		$this->SendDebug("Skriptstart", "Skript-ID: " . (int)$ScriptID . " Status: " . ($StatusArray[(int)$Result] ?? $Result), 0);
		return $Result;
	}

	private function SSH_Connect_Array(string $Command) {
		if (!$this->ReadPropertyBoolean("Open") || $this->GetParentStatus() != 102) return serialize([]);
		if (($ssh = $this->sshLogin("SSH-Connect")) === null) return serialize([]);
		$ResultArray  = [];
		$CommandArray = unserialize($Command);
		foreach ($CommandArray as $key => $cmd) $ResultArray[$key] = $ssh->exec($cmd);
		$ssh->disconnect();
		return serialize($ResultArray);
	}

	private function GetOneWireDevices() {
		if (!$this->ReadPropertyBoolean("Open") || $this->GetParentStatus() != 102) return serialize([]);
		if (($sftp = $this->sftpLogin("SFTP-Connect")) === null) return serialize([]);
		$Path = "/sys/bus/w1/devices";
		if (!$sftp->file_exists($Path)) {
			IPS_LogMessage("IPS2GPIO SFTP-Connect", "$Path nicht gefunden! Ist 1-Wire aktiviert?");
			return serialize([]);
		}
		$Sensors = [];
		foreach ($sftp->nlist($Path) as $d) {
			if (!in_array($d, ['.', '..', 'w1_bus_master1'])) $Sensors[] = $d;
		}
		return serialize($Sensors);
	}

	private function CalcBitmask() {
		$PinNotify = unserialize($this->GetBuffer("PinNotify"));
		$Bitmask   = 0;
		foreach ($PinNotify as $Pin) $Bitmask += (1 << $Pin); // Bit-Shift korrekt (pow() erzeugte float)
		$this->SetBuffer("NotifyBitmask", $Bitmask);
		return $Bitmask;
	}

	private function ConnectionTest() {
		$result = false;
		if (Sys_Ping($this->ReadPropertyString("IPAddress"), 2000)) {
			$this->SendDebug("Netzanbindung", "IP " . $this->ReadPropertyString("IPAddress") . " reagiert", 0);
			$status = @fsockopen($this->ReadPropertyString("IPAddress"), 8888, $errno, $errstr, 10);
			if (!$status) {
				IPS_LogMessage("IPS2GPIO Netzanbindung: ", "Port ist geschlossen!");
				$this->SendDebug("Netzanbindung", "Port ist geschlossen!", 0);
				$this->setPigpioStatus(false);
				IPS_LogMessage("IPS2GPIO Netzanbindung: ", "Versuche PIGPIO per SSH zu starten...");
				$this->SSH_Connect($this->buildPigpiodCmd());
				$status = @fsockopen($this->ReadPropertyString("IPAddress"), 8888, $errno, $errstr, 10);
				if (!$status) {
					IPS_LogMessage("IPS2GPIO Netzanbindung: ", "Port ist geschlossen!");
					$this->SendDebug("Netzanbindung", "Port ist geschlossen!", 0);
					$this->setPigpioStatus(false); $this->setStatusSafe(104);
				} else {
					fclose($status); $this->SendDebug("Netzanbindung", "Port ist geoeffnet", 0);
					$result = true; $this->setStatusSafe(102);
				}
			} else {
				fclose($status); $this->SendDebug("Netzanbindung", "Port ist geoeffnet", 0);
				$result = true; $this->setStatusSafe(102);
			}
		} else {
			IPS_LogMessage("GPIO Netzanbindung: ", "IP " . $this->ReadPropertyString("IPAddress") . " reagiert nicht!");
			$this->SendDebug("Netzanbindung", "IP " . $this->ReadPropertyString("IPAddress") . " reagiert nicht!", 0);
			$this->setPigpioStatus(false); $this->setStatusSafe(104);
		}
		return $result;
	}

	private function SetI2CBus($DeviceIdent) {
		// MUX-Kanal umschalten wenn Device an MUX-Bus (>=3) haengt
		$DeviceBus = $DeviceIdent >> 7;
		if ($DeviceBus >= 3) $this->SetMUX($DeviceBus);
	}

	private function SetMUX($Port) {
		$Success     = false;
		$DevicePorts = [0 => "I\xC2\xB2C-Bus 0", 1 => "I\xC2\xB2C-Bus 1"];
		for ($i = 3; $i <= 10; $i++) $DevicePorts[$i] = "MUX I\xC2\xB2C-Bus " . ($i - 3);
		if (intval($this->GetBuffer("MUX_Channel")) != $Port) {
			$this->SetBuffer("MUX_Channel", $Port);
			$MUX_Handle = $this->GetBuffer("MUX_Handle");
			$MUX        = $this->ReadPropertyInteger("MUX");
			if ($MUX_Handle >= 0) {
				if ($Port == 0 || $Port == 1) {
					// MUX deaktivieren
					$Result  = $this->CommandClientSocket(pack("L*", 60, $MUX_Handle, 0, 0), 16);
					$Success = ($Result > 0);
					$this->SendDebug("SetMUX", $Success ? "MUX ausgeschaltet" : "Fehler beim Ausschalten!", 0);
				} else {
					$DevicePort = 0;
					if ($MUX == 1) $DevicePort = (1 << ($Port - 3)); // TCA9548a: Bitmaske; 1<< statt pow() vermeidet Float auf 32-Bit
					elseif ($MUX == 2) $DevicePort = ($Port - 3) + 4; // PCA9542: CH0=0x04, CH1=0x05
					$Result  = $this->CommandClientSocket(pack("L*", 60, $MUX_Handle, $DevicePort, 0), 16);
					$Success = ($Result > 0);
					$this->SendDebug("SetMUX", $Success ? "Umschaltung auf " . $DevicePorts[$Port] : "Fehler bei Umschaltung!", 0);
				}
			} else {
				$this->SendDebug("SetMUX", "MUX konnte nicht gesetzt werden!", 0);
			}
		}
		return $Success;
	}

	private function GetI2C_DeviceHandle(int $DeviceAddress) {
		$I2C_HandleData = unserialize($this->GetBuffer("I2C_Handle"));
		return array_key_exists($DeviceAddress, $I2C_HandleData) ? $I2C_HandleData[$DeviceAddress] : -1;
	}

	private function GetI2C_HandleDevice(int $I2C_Handle) {
		// Gibt den logischen Ident (Bus<<7 + Adresse) fuer einen Handle zurueck
		$I2C_HandleData = unserialize($this->GetBuffer("I2C_Handle"));
		$found          = array_search($I2C_Handle, $I2C_HandleData);
		return ($found === false) ? -1 : $found;
	}

	private function ResetI2CHandle($MinHandle = 0) {
		$this->SendDebug("ResetI2CHandle", "I2C Handle loeschen", 0);
		$Handle = $this->CommandClientSocket(pack("L*", 54, 1, 1, 4, 0), 16);
		for ($i = $MinHandle; $i <= $Handle; $i++) $this->CommandClientSocket(pack("L*", 55, $i, 0, 0), 16);
	}

	private function ResetSerialHandle() {
		$this->SendDebug("ResetSerialHandle", "Serial Handle loeschen", 0);
		$SerialHandle = $this->CommandClientSocket(pack("L*", 76, 9600, 0, strlen("/dev/serial0")) . "/dev/serial0", 16);
		for ($i = 0; $i <= $SerialHandle; $i++) $this->CommandClientSocket(pack("L*", 77, $i, 0, 0), 16);
	}

	private function GetParentID() {
		return IPS_GetInstance($this->InstanceID)['ConnectionID'];
	}

	private function GetParentStatus() {
		return IPS_GetInstance($this->GetParentID())['InstanceStatus'];
	}

	private function CheckConfig() {
		$arrayCheckConfig = [];
		foreach (["I2C", "Serielle Schnittstelle", "Shell Zugriff", "PIGPIO Server", "1-Wire-Server"] as $key)
			$arrayCheckConfig[$key] = ["Status" => "unbekannt", "Color" => "#FFFF00"];
		$this->SetBuffer("I2C_Enabled", 0);
		if (!$this->ReadPropertyBoolean("Open") || $this->GetParentStatus() != 102) return serialize($arrayCheckConfig);

		if (($sftp = $this->sftpLogin("CheckConfig")) === null) {
			$this->SendDebug("CheckConfig", "IP " . $this->ReadPropertyString("IPAddress") . " reagiert nicht!", 0);
			return serialize($arrayCheckConfig);
		}

		// I²C Schnittstelle (Standard oder LibreELEC)
		$PathConfig = "";
		if ($sftp->file_exists("/boot/firmware/config.txt"))      $PathConfig = "/boot/firmware/config.txt";
		elseif ($sftp->file_exists("/boot/config.txt"))           $PathConfig = "/boot/config.txt";
		elseif ($sftp->file_exists("/flash/config.txt"))          $PathConfig = "/flash/config.txt";
		else { $this->SendDebug("CheckConfig", "config.txt wurde nicht gefunden!", 0); IPS_LogMessage("IPS2GPIO CheckConfig", "config.txt wurde nicht gefunden!"); }

		if ($PathConfig !== "") {
			$FileContentConfig = $sftp->get($PathConfig);
			// I2C aktiviert?
			if (preg_match("/(?:\r\n|\n|\r)(\s*)(device_tree_param|dtparam)=([^,]*,)*i2c(_arm)?(=(on|true|yes|1))(\s*)(?:$|\r\n|\n|\r)/", $FileContentConfig)) {
				$arrayCheckConfig["I2C"] = ["Status" => "Aktiviert", "Color" => "#00FF00"]; $this->SetBuffer("I2C_Enabled", 1);
				$this->SendDebug("CheckConfig", "I2C ist aktiviert", 0);
			} else {
				$arrayCheckConfig["I2C"] = ["Status" => "Deaktiviert", "Color" => "#FF0000"]; $this->SetBuffer("I2C_Enabled", 0);
				$this->SendDebug("CheckConfig", "I2C ist deaktiviert!", 0); IPS_LogMessage("IPS2GPIO CheckConfig", "I2C ist deaktiviert!");
			}
			// 1-Wire-Server aktiviert?
			if (preg_match("/(?:\r\n|\n|\r)(\s*)(dtoverlay)(=(w1-gpio))(\s*)(?:$|\r\n|\n|\r)/", $FileContentConfig)) {
				$arrayCheckConfig["1-Wire-Server"] = ["Status" => "Aktiviert", "Color" => "#00FF00"];
				$this->SendDebug("CheckConfig", "1-Wire-Server ist aktiviert", 0);
			} else {
				$arrayCheckConfig["1-Wire-Server"] = ["Status" => "Deaktiviert", "Color" => "#FF0000"];
				$this->SendDebug("CheckConfig", "1-Wire-Server ist deaktiviert!", 0);
			}
			// Serielle Schnittstelle aktiviert? (aktiviert = grün = Nutzung möglich)
			if (preg_match("/(?:\r\n|\n|\r)(\s*)(enable_uart)(=(on|true|yes|1))(\s*)(?:$|\r\n|\n|\r)/", $FileContentConfig)) {
				$arrayCheckConfig["Serielle Schnittstelle"] = ["Status" => "Aktiviert", "Color" => "#00FF00"];
				$this->SendDebug("CheckConfig", "Serielle Schnittstelle ist aktiviert!", 0);
			} else {
				$arrayCheckConfig["Serielle Schnittstelle"] = ["Status" => "Deaktiviert", "Color" => "#FF0000"];
				$this->SendDebug("CheckConfig", "Serielle Schnittstelle ist deaktiviert!", 0);
			}
		}

		// cmdline.txt (Standard oder LibreELEC)
		$PathCmdline = "";
		if ($sftp->file_exists("/boot/firmware/cmdline.txt"))     $PathCmdline = "/boot/firmware/cmdline.txt";
		elseif ($sftp->file_exists("/boot/cmdline.txt"))          $PathCmdline = "/boot/cmdline.txt";
		elseif ($sftp->file_exists("/flash/cmdline.txt"))         $PathCmdline = "/flash/cmdline.txt";
		else { $this->SendDebug("CheckConfig", "cmdline.txt wurde nicht gefunden!", 0); IPS_LogMessage("IPS2GPIO CheckConfig", "cmdline.txt wurde nicht gefunden!"); }

		if ($PathCmdline !== "") {
			$FileContentCmdline = $sftp->get($PathCmdline);
			// Shell auf serieller Schnittstelle aktiv? (aktiv = rot = Konflikt)
			if (preg_match("/console=(serial[0-9]|ttyAMA[0-9]|ttyS[0-9]|tty[0-9])/", $FileContentCmdline)) {
				$arrayCheckConfig["Shell Zugriff"] = ["Status" => "Deaktiviert", "Color" => "#00FF00"];
				$this->SendDebug("CheckConfig", "Shell-Zugriff auf serieller Schnittstelle deaktiviert", 0);
			} else {
				$arrayCheckConfig["Shell Zugriff"] = ["Status" => "Aktiviert", "Color" => "#FF0000"];
				$this->SendDebug("CheckConfig", "Shell-Zugriff auf serieller Schnittstelle aktiviert!", 0);
				IPS_LogMessage("IPS2GPIO CheckConfig", "Shell-Zugriff auf serieller Schnittstelle ist aktiviert!");
			}
		}

		// PIGPIOD-Service
		if ($sftp->file_exists("/etc/systemd/system/multi-user.target.wants/pigpiod.service")) {
			$arrayCheckConfig["PIGPIO Server"] = ["Status" => "Aktiviert", "Color" => "#00FF00"];
			$this->SendDebug("CheckConfig", "PIGPIO-Server ist aktiviert", 0);
		} else {
			$arrayCheckConfig["PIGPIO Server"] = ["Status" => "Deaktiviert", "Color" => "#FF0000"];
			$this->SendDebug("CheckConfig", "PIGPIO-Server ist deaktiviert!", 0);
			IPS_LogMessage("IPS2GPIO CheckConfig", "PIGPIO-Server ist deaktiviert!");
		}
		return serialize($arrayCheckConfig);
	}

	private function SearchI2CDevices() {
		$DeviceArray = [];
		$k           = 0;

		// Port-Beschriftungen (Bus 0/1 direkt, Bus 3-10 = MUX-Kanaele 0-7)
		$DevicePorts = [0 => "I\xC2\xB2C-Bus 0", 1 => "I\xC2\xB2C-Bus 1"];
		for ($i = 3; $i <= 10; $i++) $DevicePorts[$i] = "MUX I\xC2\xB2C-Bus " . ($i - 3);

		// Geraete-Suchliste: [Startadresse, Endadresse, Standardname]
		$deviceDefs = [
			[3,   4,   "AS3935"],
			[15,  15,  "S.USV"],
			[29,  29,  "ADXL345"],
			[32,  34,  "PCF8574|MCP23017"],
			[35,  35,  "BH1750|MCP23017"],
			[36,  39,  "PCF8574|MCP23017"],
			[56,  63,  "PCF8574"],
			[72,  79,  "PCF8591"],
			[80,  81,  "PCF8583"],
			[82,  82,  "GeCoS PWM16Out"],
			[83,  83,  "ADXL345|GeCoS PWM16Out"],
			[84,  87,  "GeCoS PWM16Out"],
			[88,  90,  "GeCoS RGBW"],
			[90,  91,  "iAQ"],
			[92,  92,  "BH1750"],
			[93,  95,  "iAQ"],
			[98,  98,  "EZO ORP"],
			[99,  99,  "EZO PH"],
			[104, 104, "MCP3424|DS3231"],
			[105, 110, "MCP3424"],
			[118, 118, "BME280/680"],
			[119, 119, "BME280/680/BMP180"],
		];

		// Adress-zu-Name-Map fuer O(1)-Zugriff
		$knownAddrMap = [];
		foreach ($deviceDefs as $def) {
			for ($addr = $def[0]; $addr <= $def[1]; $addr++) {
				$knownAddrMap[$addr] = $def[2];
			}
		}

		// Sonderadressen stillschweigend ignorieren:
		// DS2482 1-Wire-Bridge (24/0x18) und MUX TCA9548a (112/0x70)
		$ignoredAddresses = [24, 112];

		$this->SetBuffer("I2CSearch", 1);
		$I2CSearchStart = ($this->ReadPropertyInteger("I2C0") == 1) ? 0 : 1;
		$MUX = $this->ReadPropertyInteger("MUX");
		if ($MUX == 1)     $I2CSearchEnd = 10; // TCA9548a (8 Kanaele)
		elseif ($MUX == 2) $I2CSearchEnd = 5;  // PCA9542 (2 Kanaele)
		else               $I2CSearchEnd = 1;

		// Vollstaendigen gueltigen 7-Bit-Adressraum scannen (0x01..0x77 = 1..119).
		// Bekannte Adressen werden per Spezifikation identifiziert,
		// unbekannte Adressen als "Unbekanntes I2C-Device!" rot markiert (analog 1-Wire).
		$allAddresses = range(1, 119);

		for ($j = $I2CSearchStart; $j <= $I2CSearchEnd; $j++) {
			if ($j == 2) continue; // Bus 2 existiert nicht
			foreach ($allAddresses as $addr) {
				// MUX/DS2482 haben eigene Behandlung – hier ueberspringen
				if (in_array($addr, $ignoredAddresses)) continue;

				$DeviceBus = min(1, $j);
				$Handle    = $this->CommandClientSocket(pack("L*", 54, $DeviceBus, $addr, 4, 0), 16);
				if ($Handle >= 0) {
					if ($j >= 3) $this->SetMUX($j); else $this->SetMUX(0);
					$Result = $this->CommandClientSocket(pack("L*", 59, $Handle, 0, 0), 16);
					if ($Result >= 0) {
						$this->SendDebug("SearchI2CDevices", "Device auf Bus $j, Adr. $addr gefunden, Ergebnis: $Result", 0);
						$isKnown = isset($knownAddrMap[$addr]);

						// Typ: bekannte Adresse per Spezifikation, unbekannte rot wie 1-Wire
						$DeviceArray[$k][0] = $isKnown
							? $this->I2CDeviceSpecification($knownAddrMap[$addr], $Handle, $addr)
							: "Unbekanntes I\xC2\xB2C-Device!";
						$DeviceArray[$k][1] = $addr;
						$DeviceArray[$k][2] = $DevicePorts[$j];

						// Instanz-ID per ausgelagerter Hilfsmethode ermitteln
						$foundInstanceID    = $this->findI2CInstanceID($addr, $j, $DevicePorts);
						$DeviceArray[$k][3] = $foundInstanceID;

						if ($foundInstanceID == 0) {
							$DeviceArray[$k][4] = "Erkannt";
							// Bekannte Geraete grau, unbekannte rot (analog Unbekannter 1-Wire-Typ!)
							$DeviceArray[$k][5] = $isKnown ? "#C0C0C0" : "#FF0000";
						} else {
							$propOpen = @IPS_GetProperty($foundInstanceID, 'Open');
							if ($propOpen === false) {
								$DeviceArray[$k][4] = "Deaktiviert"; $DeviceArray[$k][5] = "#FFFF00";
							} else {
								$DeviceArray[$k][4] = "OK"; $DeviceArray[$k][5] = "#00FF00";
							}
						}
						$k++;
					}
					$this->CommandClientSocket(pack("L*", 55, $Handle, 0, 0), 16); // Handle freigeben
				}
			}
		}
		$this->SetBuffer("I2CSearch", 0);
		return serialize($DeviceArray);
	}

	/**
	 * Konsolidierte Instanz-ID-Suche fuer I2C-Devices.
	 * 1. Versuch: I2C_InstanceMap-Buffer (schnell, direkte Registrierung)
	 * 2. Versuch: IPS-Instanzliste (DeviceAddress+DeviceBus oder InstanceSummary)
	 * Gibt 0 zurueck wenn keine Instanz gefunden.
	 */
	private function findI2CInstanceID(int $addr, int $bus, array $DevicePorts): int {
		// 1. I2C_InstanceMap-Buffer
		$I2C_InstanceMap = unserialize($this->GetBuffer("I2C_InstanceMap"));
		if (!is_array($I2C_InstanceMap)) $I2C_InstanceMap = [];
		$mapKey          = ($bus << 7) + $addr;
		$foundInstanceID = isset($I2C_InstanceMap[$mapKey]) ? intval($I2C_InstanceMap[$mapKey]) : 0;
		if ($foundInstanceID > 0) return $foundInstanceID;

		// 2. IPS-Instanzliste (Methode A: Properties; Methode B: InstanceSummary)
		foreach (IPS_GetInstanceList() as $iid) {
			$inst = @IPS_GetInstance($iid);
			if (!$inst || !isset($inst['ConnectionID']) || $inst['ConnectionID'] != $this->InstanceID) continue;

			// Methode A: DeviceAddress + DeviceBus direkt vergleichen
			$propAddr = @IPS_GetProperty($iid, 'DeviceAddress');
			$propBus  = @IPS_GetProperty($iid, 'DeviceBus');
			if ($propAddr !== false && $propAddr !== null && $propBus !== false && $propBus !== null
				&& intval($propAddr) == $addr && intval($propBus) == $bus) {
				return $iid;
			}

			// Methode B: InstanceSummary (Fallback ueber Hex-Adresse + Bus-Name)
			$hexAddr = strtolower(dechex($addr));
			$summary = isset($inst['InstanceSummary']) ? $inst['InstanceSummary'] : '';
			if ($summary !== '' && stripos($summary, '0x' . $hexAddr) !== false) {
				$busName      = $DevicePorts[$bus] ?? '';
				$busNameAscii = str_replace("\xC2\xB2", '2', $busName);
				if (stripos($summary, $busName) !== false || stripos($summary, $busNameAscii) !== false) {
					return $iid;
				}
			}
		}
		return 0;
	}

	private function I2CDeviceSpecification($DefaultDeviceName, int $Handle, int $DeviceAddress) {
		$DeviceName = $DefaultDeviceName;
		if ($DeviceAddress == 118 || $DeviceAddress == 119) {
			// BME280/BME680/BMP180 per Chip-ID unterscheiden
			$Result = $this->CommandClientSocket(pack("L*", 61, $Handle, 0xD0, 0), 16);
			if ($Result >= 0) {
				if ($Result == 96) $DeviceName = "BME280";
				elseif ($Result == 97) $DeviceName = "BME680";
				elseif ($Result == 85) $DeviceName = "BMP180";
			} else $this->SendDebug("I2CDeviceSpecification", "Fehler beim Lesen der BME Chip ID", 0);
		} elseif ($DeviceAddress == 83) {
			// ADXL345 vs. GeCoS PWM16Out per Chip-ID unterscheiden
			$Result = $this->CommandClientSocket(pack("L*", 61, $Handle, 0x00, 0), 16);
			if ($Result >= 0) $DeviceName = ($Result == 0xE5) ? "ADXL345" : "GeCoS PWM16Out";
			else $this->SendDebug("I2CDeviceSpecification", "Fehler beim Lesen der ADXL345 Chip ID", 0);
		} elseif ($DeviceAddress == 104) {
			// MCP3424 vs. DS3231: Sekunden-Register lesen und 1 s warten -> DS3231 aendert sich
			$Result = $this->CommandClientSocket(pack("L*", 67, $Handle, 0x00, 4, 1), 17);
			$this->SendDebug("I2CDeviceSpecification", "MCP3424|DS3231 Test-Lesen: $Result", 0);
			if ($Result >= 0 && is_array(unserialize($Result))) {
				$DataArray = unserialize($Result); $Sec_1 = $DataArray[1] & 127;
				IPS_Sleep(1000);
				$Result = $this->CommandClientSocket(pack("L*", 67, $Handle, 0x00, 4, 1), 17);
				$this->SendDebug("I2CDeviceSpecification", "MCP3424|DS3231 Test-Lesen 2: $Result", 0);
				if ($Result >= 0 && is_array(unserialize($Result))) {
					$DataArray  = unserialize($Result); $Sec_2 = $DataArray[1] & 127;
					$this->SendDebug("I2CDeviceSpecification", "Sekunden: $Sec_1 - $Sec_2", 0);
					$DeviceName = ($Sec_1 == $Sec_2) ? "MCP3424" : "DS3231";
				}
			} else $this->SendDebug("I2CDeviceSpecification", "Fehler beim Lesen der MCP3424|DS3231 Daten", 0);
		}
		return $DeviceName;
	}

	private function GetErrorText(int $ErrorNumber) {
		$ErrorMessage = [
			1 => "PI_INIT_FAILED", 2 => "PI_BAD_USER_GPIO", 3 => "PI_BAD_GPIO", 4 => "PI_BAD_MODE",
			5 => "PI_BAD_LEVEL", 6 => "PI_BAD_PUD", 7 => "PI_BAD_PULSEWIDTH", 8 => "PI_BAD_DUTYCYCLE",
			15 => "PI_BAD_WDOG_TIMEOUT", 21 => "PI_BAD_DUTYRANGE", 24 => "PI_NO_HANDLE", 25 => "PI_BAD_HANDLE",
			35 => "PI_BAD_WAVE_BAUD", 36 => "PI_TOO_MANY_PULSES", 37 => "PI_TOO_MANY_CHARS",
			38 => "PI_NOT_SERIAL_GPIO", 41 => "PI_NOT_PERMITTED", 42 => "PI_SOME_PERMITTED",
			43 => "PI_BAD_WVSC_COMMND", 44 => "PI_BAD_WVSM_COMMND", 45 => "PI_BAD_WVSP_COMMND",
			46 => "PI_BAD_PULSELEN", 47 => "PI_BAD_SCRIPT", 48 => "PI_BAD_SCRIPT_ID",
			49 => "PI_BAD_SER_OFFSET", 50 => "PI_GPIO_IN_USE", 51 => "PI_BAD_SERIAL_COUNT",
			52 => "PI_BAD_PARAM_NUM", 53 => "PI_DUP_TAG", 54 => "PI_TOO_MANY_TAGS",
			55 => "PI_BAD_SCRIPT_CMD", 56 => "PI_BAD_VAR_NUM", 57 => "PI_NO_SCRIPT_ROOM",
			58 => "PI_NO_MEMORY", 59 => "PI_SOCK_READ_FAILED", 60 => "PI_SOCK_WRIT_FAILED",
			61 => "PI_TOO_MANY_PARAM", 62 => "PI_SCRIPT_NOT_READY", 63 => "PI_BAD_TAG",
			64 => "PI_BAD_MICS_DELAY", 65 => "PI_BAD_MILS_DELAY", 66 => "PI_BAD_WAVE_ID",
			67 => "PI_TOO_MANY_CBS", 68 => "PI_TOO_MANY_OOL", 69 => "PI_EMPTY_WAVEFORM",
			70 => "PI_NO_WAVEFORM_ID", 71 => "PI_I2C_OPEN_FAILED", 72 => "PI_SER_OPEN_FAILED",
			73 => "PI_SPI_OPEN_FAILED", 74 => "PI_BAD_I2C_BUS", 75 => "PI_BAD_I2C_ADDR",
			76 => "PI_BAD_SPI_CHANNEL", 77 => "PI_BAD_FLAGS", 78 => "PI_BAD_SPI_SPEED",
			79 => "PI_BAD_SER_DEVICE", 80 => "PI_BAD_SER_SPEED", 81 => "PI_BAD_PARAM",
			82 => "PI_I2C_WRITE_FAILED", 83 => "PI_I2C_READ_FAILED", 84 => "PI_BAD_SPI_COUNT",
			85 => "PI_SER_WRITE_FAILED", 86 => "PI_SER_READ_FAILED", 87 => "PI_SER_READ_NO_DATA",
			88 => "PI_UNKNOWN_COMMAND", 89 => "PI_SPI_XFER_FAILED", 91 => "PI_NO_AUX_SPI",
			92 => "PI_NOT_PWM_GPIO", 93 => "PI_NOT_SERVO_GPIO", 94 => "PI_NOT_HCLK_GPIO",
			95 => "PI_NOT_HPWM_GPIO", 96 => "PI_BAD_HPWM_FREQ", 97 => "PI_BAD_HPWM_DUTY",
			98 => "PI_BAD_HCLK_FREQ", 99 => "PI_BAD_HCLK_PASS", 100 => "PI_HPWM_ILLEGAL",
			101 => "PI_BAD_DATABITS", 102 => "PI_BAD_STOPBITS", 103 => "PI_MSG_TOOBIG",
			104 => "PI_BAD_MALLOC_MODE", 107 => "PI_BAD_SMBUS_CMD", 108 => "PI_NOT_I2C_GPIO",
			109 => "PI_BAD_I2C_WLEN", 110 => "PI_BAD_I2C_RLEN", 111 => "PI_BAD_I2C_CMD",
			112 => "PI_BAD_I2C_BAUD", 113 => "PI_CHAIN_LOOP_CNT", 114 => "PI_BAD_CHAIN_LOOP",
			115 => "PI_CHAIN_COUNTER", 116 => "PI_BAD_CHAIN_CMD", 117 => "PI_BAD_CHAIN_DELAY",
			118 => "PI_CHAIN_NESTING", 119 => "PI_CHAIN_TOO_BIG", 120 => "PI_DEPRECATED",
			121 => "PI_BAD_SER_INVERT", 124 => "PI_BAD_FOREVER", 125 => "PI_BAD_FILTER",
			126 => "PI_BAD_PAD", 127 => "PI_BAD_STRENGTH", 128 => "PI_FIL_OPEN_FAILED",
			129 => "PI_BAD_FILE_MODE", 130 => "PI_BAD_FILE_FLAG", 131 => "PI_BAD_FILE_READ",
			132 => "PI_BAD_FILE_WRITE", 133 => "PI_FILE_NOT_ROPEN", 134 => "PI_FILE_NOT_WOPEN",
			135 => "PI_BAD_FILE_SEEK", 136 => "PI_NO_FILE_MATCH", 137 => "PI_NO_FILE_ACCESS",
			138 => "PI_FILE_IS_A_DIR", 139 => "PI_BAD_SHELL_STATUS", 140 => "PI_BAD_SCRIPT_NAME",
			141 => "PI_BAD_SPI_BAUD", 142 => "PI_NOT_SPI_GPIO", 143 => "PI_BAD_EVENT_ID",
		];
		return array_key_exists($ErrorNumber, $ErrorMessage) ? $ErrorMessage[$ErrorNumber] : "unknown Error -" . $ErrorNumber;
	}

	private function GetHardware(int $RevNumber) {
		$Hardware = [
			2  => "Rev.0002 Model B PCB-Rev. 1.0 256MB",
			3  => "Rev.0003 Model B PCB-Rev. 1.0 256MB",
			4  => "Rev.0004 Model B PCB-Rev. 2.0 256MB Sony",
			5  => "Rev.0005 Model B PCB-Rev. 2.0 256MB Qisda",
			6  => "Rev.0006 Model B PCB-Rev. 2.0 256MB Egoman",
			7  => "Rev.0007 Model A PCB-Rev. 2.0 256MB Egoman",
			8  => "Rev.0008 Model A PCB-Rev. 2.0 256MB Sony",
			9  => "Rev.0009 Model A PCB-Rev. 2.0 256MB Qisda",
			13 => "Rev.000d Model B PCB-Rev. 2.0 512MB Egoman",
			14 => "Rev.000e Model B PCB-Rev. 2.0 512MB Sony",
			15 => "Rev.000f Model B PCB-Rev. 2.0 512MB Qisda",
			16 => "Rev.0010 Model B+ PCB-Rev. 1.0 512MB Sony",
			17 => "Rev.0011 Compute Module PCB-Rev. 1.0 512MB Sony",
			18 => "Rev.0012 Model A+ PCB-Rev. 1.1 256MB Sony",
			19 => "Rev.0013 Model B+ PCB-Rev. 1.2 512MB",
			20 => "Rev.0014 Compute Module PCB-Rev. 1.0 512MB Embest",
			21 => "Rev.0015 Model A+ PCB-Rev. 1.1 256/512MB Embest",
			10489920 => "Rev.a01040 2 Model B PCB-Rev. 1.0 1GB",
			10489921 => "Rev.a01041 2 Model B PCB-Rev. 1.1 1GB Sony",
			10620993 => "Rev.a21041 2 Model B PCB-Rev. 1.1 1GB Embest",
			10625090 => "Rev.a22042 2 Model B PCB-Rev. 1.2 1GB Embest",
			9437330  => "Rev.900092 Zero PCB-Rev. 1.2 512MB Sony",
			9437331  => "Rev.900093 Zero PCB-Rev. 1.3 512MB Sony",
			9437377  => "Rev.9000c1 Zero W PCB-Rev. 1.1 512MB Sony",
			10494082 => "Rev.a02082 3 Model B PCB-Rev. 1.2 1GB Sony",
			10625154 => "Rev.a22082 3 Model B PCB-Rev. 1.2 1GB Embest",
			44044353 => "Rev.2a01041 2 Model B PCB-Rev. 1.1 1GB Sony (overvoltage)",
			10494163 => "Rev.a020d3 3 Model B+ PCB-Rev. 1.3 1GB Sony",
			10494164 => "Rev.a020d4 3 Model B+ PCB-Rev. 1.4 1GB Sony",
			10498321 => "Rev.a03111 4 Model B PCB-Rev. 1.1 1GB Sony UK",
			11546897 => "Rev.b03111 4 Model B PCB-Rev. 1.1 2GB Sony UK",
			12595473 => "Rev.c03111 4 Model B PCB-Rev. 1.1 4GB Sony UK",
		];
		$HardwareText = array_key_exists($RevNumber, $Hardware) ? $Hardware[$RevNumber] : "Unbekannte Revisions Nummer!";
		$this->SetBuffer("Default_I2C_Bus",    ($RevNumber <= 3) ? 0 : 1);
		$this->SetBuffer("Default_Serial_Bus", ($RevNumber == 10494082 || $RevNumber == 10625154) ? 1 : 0);
		return $HardwareText;
	}

	private function GetOWHardware(string $FamilyCode) {
		$OWHardware = [
			"05" => "DS2405 Switch",
			"10" => "DS18S20 Temperatur",
			"12" => "DS2406 Switch",
			"1D" => "DS2423 Counter",
			"26" => "DS2438 Batt.Monitor",
			"28" => "DS18B20 Temperatur",
			"29" => "DS2408 8 Ch. Switch",
			"3A" => "DS2413 2 Ch. Switch",
		];
		return array_key_exists($FamilyCode, $OWHardware) ? $OWHardware[$FamilyCode] : "Unbekannter 1-Wire-Typ!";
	}

	private function OWInstanceArraySearch(string $SearchKey, string $SearchValue) {
		$Result          = 0;
		$OWInstanceArray = unserialize($this->GetBuffer("OWInstanceArray"));
		if (is_array($OWInstanceArray) && count($OWInstanceArray, COUNT_RECURSIVE) >= 4) {
			foreach ($OWInstanceArray as $Type => $Properties) {
				foreach ($Properties as $Property => $Value) {
					if ($Property == $SearchKey && $Value == $SearchValue) $Result = $Type;
				}
			}
		}
		return $Result;
	}

	// =========================================================
	// 1-Wire / DS2482
	// =========================================================

	private function DS2482Reset() {
		$this->SendDebug("DS2482Reset", "Resetting DS2482", 0);
		$Result = $this->CommandClientSocket(pack("L*", 60, $this->GetBuffer("OW_Handle"), 240, 0), 16);
		if ($Result < 0) $this->SendDebug("DS2482Reset", "DS2482 Reset Failed", 0);
	}

	/**
	 * Wartet bis DS2482 Busy-Bit (Bit 0) geloescht ist.
	 * Gibt Status-Register-Byte zurueck oder -1 bei Fehler/Timeout.
	 * Konsolidiert 4 identische Busy-Wait-Schleifen.
	 */
	private function owWaitNotBusy(string $context, int $maxLoops = 100): int {
		for ($i = 0; $i < $maxLoops; $i++) {
			$Data = $this->OWStatusRegister();
			if ($Data < 0) { $this->SendDebug($context, "I2C Read Status Failed", 0); return -1; }
			if (!($Data & 0x01)) return $Data; // nicht mehr busy
			IPS_Sleep(10);
		}
		$this->SendDebug($context, "One-Wire busy too long", 0);
		return -1;
	}

	private function OWSearch(int $SearchNumber) {
		$this->SendDebug("SearchOWDevices", "Suche gestartet", 0);
		$bitNumber               = 1; $lastZero = 0;
		$deviceAddress4ByteIndex = 1; $deviceAddress4ByteMask = 1;
		if ($this->GetBuffer("owLastDevice")) {
			$this->SendDebug("SearchOWDevices", "OW Suche beendet", 0);
			$this->SetBuffer("owLastDevice", 0); $this->SetBuffer("owLastDiscrepancy", 0);
			$this->SetBuffer("owDeviceAddress_0", -1); $this->SetBuffer("owDeviceAddress_1", -1);
		} else {
			if (!$this->OWReset()) { $this->SetBuffer("owLastDiscrepancy", 0); return 0; }
			$this->OWWriteByte(240); // Search ROM
			do {
				if ($bitNumber < $this->GetBuffer("owLastDiscrepancy"))
					$this->SetBuffer("owTripletDirection", ($this->GetBuffer("owDeviceAddress_" . $deviceAddress4ByteIndex) & $deviceAddress4ByteMask) ? 1 : 0);
				elseif ($bitNumber == $this->GetBuffer("owLastDiscrepancy"))
					$this->SetBuffer("owTripletDirection", 1);
				else
					$this->SetBuffer("owTripletDirection", 0);

				if (!$this->OWTriplet()) return 0;

				if ($this->GetBuffer("owTripletFirstBit") == 0 && $this->GetBuffer("owTripletSecondBit") == 0 && $this->GetBuffer("owTripletDirection") == 0)
					$lastZero = $bitNumber;
				if ($this->GetBuffer("owTripletFirstBit") == 1 && $this->GetBuffer("owTripletSecondBit") == 1) break;

				if ($this->GetBuffer("owTripletDirection") == 1)
					$this->SetBuffer("owDeviceAddress_" . $deviceAddress4ByteIndex, intval($this->GetBuffer("owDeviceAddress_" . $deviceAddress4ByteIndex)) | $deviceAddress4ByteMask);
				else
					$this->SetBuffer("owDeviceAddress_" . $deviceAddress4ByteIndex, intval($this->GetBuffer("owDeviceAddress_" . $deviceAddress4ByteIndex)) & (~$deviceAddress4ByteMask));
				$bitNumber++;
				$deviceAddress4ByteMask <<= 1;
				// Plattformunabhaengiger Overflow-Check fuer 32-Bit- UND 64-Bit-PHP:
				// 64-Bit: mask -> 0x100000000 nach <<1; 0x100000000 > 0x80000000 = TRUE -> Reset.
				// 32-Bit: mask -> -2147483648 (signed Overflow nach <<1 auf 0x40000000);
				//   weder ==0 noch > 0x7FFFFFFF -> weiter; naechstes <<1 -> 0 -> ==0 -> Reset.
				// In beiden Faellen werden korrekt alle 32 Bits pro Wort verarbeitet.
				if ($deviceAddress4ByteMask == 0 || $deviceAddress4ByteMask > 0x80000000) {
					$deviceAddress4ByteIndex--;
					$deviceAddress4ByteMask = 1;
				}
			} while ($deviceAddress4ByteIndex > -1);

			if ($bitNumber == 65) {
				$this->SetBuffer("owLastDiscrepancy", $lastZero);
				$this->SetBuffer("owLastDevice", ($lastZero == 0) ? 1 : 0);
				$SerialNumber = $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_0")) . $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_1"));
				$FamilyCode   = substr($SerialNumber, -2);
				$this->SendDebug("SearchOWDevices", "Device Address = " . $SerialNumber, 0);
				$OWDeviceArray = unserialize($this->GetBuffer("OWDeviceArray"));
				$OWDeviceArray[$SearchNumber][0] = $this->GetOWHardware($FamilyCode);
				$OWDeviceArray[$SearchNumber][1] = $SerialNumber;
				// InstanceID ermitteln
				$owInstanceID = $this->OWInstanceArraySearch("DeviceSerial", $SerialNumber);
				if ($owInstanceID == 0) {
					foreach (IPS_GetInstanceList() as $iid) {
						$inst = @IPS_GetInstance($iid);
						if (!$inst || !isset($inst['ConnectionID']) || $inst['ConnectionID'] != $this->InstanceID) continue;
						$configJson = @IPS_GetConfiguration($iid);
						if (is_string($configJson) && $configJson !== '') {
							$configArray = @json_decode($configJson, true);
							if (is_array($configArray)) {
								foreach ($configArray as $propValue) {
									if (is_string($propValue) && strtoupper(trim($propValue)) === strtoupper($SerialNumber)) {
										$owInstanceID = $iid; break 2;
									}
								}
							}
						}
						$summary = isset($inst['InstanceSummary']) ? trim($inst['InstanceSummary']) : '';
						if ($summary !== '') {
							$summaryStripped = trim(preg_replace('/^[A-Za-z]+:\s*/u', '', $summary));
							if (strtoupper($summaryStripped) === strtoupper($SerialNumber) || stripos($summary, $SerialNumber) !== false) {
								$owInstanceID = $iid; break;
							}
						}
					}
				}
				$OWDeviceArray[$SearchNumber][2] = $owInstanceID;
				if ($owInstanceID == 0) {
					$OWDeviceArray[$SearchNumber][3] = "Erkannt";
					$OWDeviceArray[$SearchNumber][4] = ($OWDeviceArray[$SearchNumber][0] === "Unbekannter 1-Wire-Typ!") ? "#FF0000" : "#C0C0C0";
				} else {
					$propOpen = @IPS_GetProperty($owInstanceID, 'Open');
					if ($propOpen === false) { $OWDeviceArray[$SearchNumber][3] = "Deaktiviert"; $OWDeviceArray[$SearchNumber][4] = "#FFFF00"; }
					else { $OWDeviceArray[$SearchNumber][3] = "OK"; $OWDeviceArray[$SearchNumber][4] = "#00FF00"; }
				}
				$OWDeviceArray[$SearchNumber][5] = $this->GetBuffer("owDeviceAddress_0");
				$OWDeviceArray[$SearchNumber][6] = $this->GetBuffer("owDeviceAddress_1");
				$this->SetBuffer("OWDeviceArray", serialize($OWDeviceArray));
				if (!$this->OWCheckCRC()) $this->SendDebug("SearchOWDevices", "CRC check failed", 0);
				return 1;
			}
		}
		$this->SendDebug("SearchOWDevices", "No One-Wire Devices Found", 0);
		$this->SetBuffer("owLastDiscrepancy", 0); $this->SetBuffer("owLastDevice", 0);
		return 0;
	}

	private function OWCheckCRC() {
		$crc     = 0;
		$da32bit = $this->GetBuffer("owDeviceAddress_1");
		for ($j = 0; $j < 4; $j++) { $crc = $this->AddCRC($da32bit & 0xFF, $crc); $da32bit >>= 8; }
		$da32bit = $this->GetBuffer("owDeviceAddress_0");
		for ($j = 0; $j < 3; $j++) { $crc = $this->AddCRC($da32bit & 0xFF, $crc); $da32bit >>= 8; }
		if (($da32bit & 0xFF) == $crc) { $this->SendDebug("OWCheckCRC", "CRC Passed", 0); return 1; }
		return 0;
	}

	private function AddCRC($inbyte, $crc) {
		for ($j = 0; $j < 8; $j++) {
			$mix  = ($crc ^ $inbyte) & 0x01; $crc >>= 1;
			if ($mix) $crc ^= 0x8C;
			$inbyte >>= 1;
		}
		return $crc;
	}

	private function OWReset() {
		$this->SendDebug("OWReset", "I2C Reset", 0);
		$Result = $this->CommandClientSocket(pack("L*", 60, $this->GetBuffer("OW_Handle"), 180, 0), 16);
		if ($Result < 0) { $this->SendDebug("OWReset", "I2C Reset Failed", 0); return 0; }
		$Data = $this->owWaitNotBusy("OWReset");
		if ($Data < 0) return 0;
		if ($Data & 0x04) { $this->SendDebug("OWReset", "One-Wire Short Detected", 0); return 0; }
		if ($Data & 0x02) return 1; // Presence Pulse OK
		$this->SendDebug("OWReset", "No One-Wire Devices Found", 0);
		return 0;
	}

	private function OWWriteByte($byte) {
		// Read-Pointer auf Status-Register setzen
		$Result = $this->CommandClientSocket(pack("LLLLCC", 57, $this->GetBuffer("OW_Handle"), 0, 2, 225, 240), 16);
		if ($Result < 0) { $this->SendDebug("OWWriteByte", "I2C Write Failed", 0); return -1; }
		if ($this->owWaitNotBusy("OWWriteByte") < 0) return -1;
		// Write-Byte-Kommando senden
		$Result = $this->CommandClientSocket(pack("LLLLCC", 57, $this->GetBuffer("OW_Handle"), 0, 2, 165, $byte), 16);
		if ($Result < 0) { $this->SendDebug("OWWriteByte", "I2C Write Byte Failed", 0); return -1; }
		if ($this->owWaitNotBusy("OWWriteByte") < 0) return -1;
		return 0;
	}

	private function OWTriplet() {
		if ($this->GetBuffer("owTripletDirection") > 0) $this->SetBuffer("owTripletDirection", 255);
		$Result = $this->CommandClientSocket(pack("LLLLCC", 57, $this->GetBuffer("OW_Handle"), 0, 2, 120, $this->GetBuffer("owTripletDirection")), 16);
		if ($Result < 0) {
			$this->SendDebug("OWTriplet", "OneWire Triplet Failed", 0);
			// Fix: 0 statt -1 – Aufrufer prueft !OWTriplet(); -1 ist truthy in PHP -> Fehler wurde ignoriert
			return 0;
		}
		$Data = $this->owWaitNotBusy("OWTriplet");
		if ($Data < 0) return 0;
		$this->SetBuffer("owTripletFirstBit",  ($Data & 0x20) ? 1 : 0);
		$this->SetBuffer("owTripletSecondBit", ($Data & 0x40) ? 1 : 0);
		$this->SetBuffer("owTripletDirection", ($Data & 0x80) ? 1 : 0);
		return 1;
	}

	private function OWSelect() {
		$this->SendDebug("OWSelect", "Selecting device", 0);
		$this->OWWriteByte(85); // Match ROM
		for ($i = 1; $i >= 0; $i--) {
			$da32bit = $this->GetBuffer("owDeviceAddress_" . $i);
			for ($j = 0; $j < 4; $j++) { $this->OWWriteByte($da32bit & 255); $da32bit >>= 8; }
		}
	}

	private function OWRead_18B20_Temperature() {
		$data = []; $celsius = -99;
		for ($i = 0; $i < 5; $i++) $data[$i] = $this->OWReadByte();
		$raw = ($data[1] << 8) | $data[0]; $SignBit = $raw & 0x8000;
		if ($SignBit) $raw = ($raw ^ 0xffff) + 1;
		$cfg = $data[4] & 0x60;
		if ($cfg == 0x60)       $this->SendDebug("OWReadTemperature", "12 bit resolution", 0);
		elseif ($cfg == 0x40) { $this->SendDebug("OWReadTemperature", "11 bit resolution", 0); $raw &= 0xFFFE; }
		elseif ($cfg == 0x20) { $this->SendDebug("OWReadTemperature", "10 bit resolution", 0); $raw &= 0xFFFC; }
		else                  { $this->SendDebug("OWReadTemperature", "9 bit resolution",  0); $raw &= 0xFFF8; }
		$celsius = $raw / 16.0;
		if ($SignBit) $celsius *= -1;
		$SerialNumber = $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_0")) . $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_1"));
		$this->SendDebug("OWRead_18B20_Temperature", "Addr=$SerialNumber Temp=$celsius", 0);
		return $celsius;
	}

	private function OWRead_18S20_Temperature() {
		$data = []; $celsius = -99;
		for ($i = 0; $i < 2; $i++) $data[$i] = $this->OWReadByte();
		$raw = ($data[1] << 8) | $data[0]; $SignBit = $raw & 0x8000;
		if ($SignBit) $raw = ($raw ^ 0xffff) + 1;
		$celsius = $raw / 2.0;
		if ($SignBit) $celsius *= -1;
		$SerialNumber = $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_0")) . $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_1"));
		$this->SendDebug("OWRead_18S20_Temperature", "Addr=$SerialNumber Temp=$celsius", 0);
		return $celsius;
	}

	private function OWRead_2413_State() {
		$result       = $this->OWReadByte();
		$SerialNumber = $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_0")) . $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_1"));
		$this->SendDebug("OWRead_2413_State", "Addr=$SerialNumber State=$result", 0);
		return $result;
	}

	private function OWReadByte() {
		// Read-Pointer auf Status-Register setzen
		$Result = $this->CommandClientSocket(pack("LLLLCC", 57, $this->GetBuffer("OW_Handle"), 0, 2, 225, 240), 16);
		if ($Result < 0) { $this->SendDebug("OWReadByte", "I2C Write Failed", 0); return -1; }
		if ($this->owWaitNotBusy("OWReadByte") < 0) return -1;
		// Read-Byte-Kommando senden
		$Result = $this->CommandClientSocket(pack("L*", 60, $this->GetBuffer("OW_Handle"), 150, 0), 16);
		if ($Result < 0) { $this->SendDebug("OWReadByte", "I2C Write read-request Failed", 0); return -1; }
		if ($this->owWaitNotBusy("OWReadByte") < 0) return -1;
		// Read-Pointer auf Data-Register setzen und Byte lesen
		$Result = $this->CommandClientSocket(pack("LLLLCC", 57, $this->GetBuffer("OW_Handle"), 0, 2, 225, 225), 16);
		if ($Result < 0) { $this->SendDebug("OWReadByte", "I2C Write Failed", 0); return -1; }
		$Data = $this->CommandClientSocket(pack("L*", 59, $this->GetBuffer("OW_Handle"), 0, 0), 16);
		if ($Data < 0) { $this->SendDebug("OWReadByte", "I2C Read Status Failed", 0); return -1; }
		return $Data;
	}

	private function OWStatusRegister() {
		// Read-Pointer auf Status-Register setzen und Status lesen
		$Result = $this->CommandClientSocket(pack("LLLLCC", 57, $this->GetBuffer("OW_Handle"), 0, 2, 225, 240), 16);
		if ($Result < 0) { $this->SendDebug("OWStatusRegister", "I2C Write Failed", 0); return -1; }
		return $this->CommandClientSocket(pack("L*", 59, $this->GetBuffer("OW_Handle"), 0, 0), 16);
	}

	private function OWVerify() {
		$owDeviceAddress_0_backup = $this->GetBuffer("owDeviceAddress_0");
		$owDeviceAddress_1_backup = $this->GetBuffer("owDeviceAddress_1");
		$ld_backup  = $this->GetBuffer("owLastDiscrepancy");
		$ldf_backup = $this->GetBuffer("owLastDevice");
		$this->SetBuffer("owLastDiscrepancy", 64); $this->SetBuffer("owLastDevice", 0);
		if ($this->OWSearch(0)) {
			$Result = ($owDeviceAddress_0_backup == $this->GetBuffer("owDeviceAddress_0") &&
			           $owDeviceAddress_1_backup == $this->GetBuffer("owDeviceAddress_1")) ? 1 : 0;
			// Suchzustand wiederherstellen – verhindert Korruption nachfolgender OWSearch-Aufrufe
			$this->SetBuffer("owLastDiscrepancy", $ld_backup);
			$this->SetBuffer("owLastDevice",      $ldf_backup);
		} else {
			$Result = 0;
			$this->SetBuffer("owDeviceAddress_0", $owDeviceAddress_0_backup);
			$this->SetBuffer("owDeviceAddress_1", $owDeviceAddress_1_backup);
			$this->SetBuffer("owLastDiscrepancy", $ld_backup);
			$this->SetBuffer("owLastDevice",      $ldf_backup);
		}
		return $Result;
	}

	private function OWRead_2438() {
		$data = []; $Celsius = -99; $Voltage = -99; $Current = -99;
		for ($i = 0; $i <= 6; $i++) $data[$i] = $this->OWReadByte();
		// $data[0]=Status $data[1]=Temp-LSB $data[2]=Temp-MSB
		// $data[3]=Volt-LSB $data[4]=Volt-MSB $data[5]=Curr-LSB $data[6]=Curr-MSB
		// Temperatur (2's complement, 13 Bit)
		$raw = ($data[2] << 8) | $data[1]; $SignBit = $raw & 0x8000;
		if ($SignBit) $raw = ($raw ^ 0xffff) + 1;
		$raw >>= 3; $Celsius = $raw / 32.0;
		if ($SignBit) $Celsius *= -1;
		// Spannung (10 Bit, 10 mV/LSB)
		$raw = ($data[4] << 8) | $data[3]; $raw &= 0x3FF; $Voltage = $raw * 0.01;
		// Strom (Vorzeichen vor Maskierung pruefen – fix: war nach & 0x3FF -> immer 0)
		$raw = ($data[6] << 8) | $data[5]; $SignBit = $raw & 0x8000;
		$raw &= 0x3FF; $Current = $raw * 0.2441;
		if ($SignBit) $Current *= -1;
		$SerialNumber = $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_0")) . $this->owAddrToHex8($this->GetBuffer("owDeviceAddress_1"));
		$this->SendDebug("OWRead_2438", "Addr=$SerialNumber Temp=$Celsius V=$Voltage I=$Current", 0);
		return [$Celsius, $Voltage, $Current];
	}
}
?>