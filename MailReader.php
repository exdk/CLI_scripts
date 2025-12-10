<?php

namespace App\Mailers;

use App\Helper\WmsCaller;
use App\Http\Controllers\Orders\IncomeController;
use App\Http\Controllers\Orders\OutcomeController;
use App\Imports\FlightsImport;
use App\Models\Company;
use App\Models\EmailsRead;
use App\Models\File;
use App\Models\OrderLog;
use DateTime;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class MailReader
{
    /**
     * Номер заявки текущей итерации обработки писем (текущего письма)
     * @var null | string
     */
    private static ?string $orderId = null;

    /**
     * WMS ID компании текущей итерации обработки писем (текущего письма)
     * @var null | string
     */
    private static ?string $companyWmsId = null;


    /**
     * Тип заявки текущей итерации обработки писем
     * @var null | string
     */
    private static ?string $orderType = null;

    /**
     * Перевозчик согласно транспортной заявке текущего письма
     * @var string|null
     */
    private static ?string $carrier = null;

    /**
     * Обозначение принадлежности email к компании-поклажедателя (его WMS_ID)
     * @var array
     */
    private static array $clientsEmails = [
        'reporting_1c@elica.com'    => [
            'ids' => [
                '7e40e90a-184b-11ef-aaf3-f4034359b8bd', // элика
            ],
            'names' => [
                'элика'
            ],
            'answer' => [
                'v.toropchina@elica.com',
                'o.sorokina@elica.com'
            ]
        ],

        'noreply@jackys.com.ru'  => [
            'ids' => [
                '4dc2c3f2-734b-11ea-aecb-68b599cc4ea2', // смарт дистрибьюшен
            ],
            'names' => [
                'смарт'
            ],
            'answer' => [
                'ga@jackys.com.ru',
                'akinenkova@smarttechnika.ru',
                'alexey.yurchenko@jackys.com.ru'
            ]
        ],

        'noreply@smarttechnika.ru'  => [
            'ids' => [
                'f1b494b5-2416-11eb-8bb0-68b599cc4ea2', // бизнес контроль
            ],
            'names' => [
                'бизнес',
            ],
            'answer' => [
                'ga@jackys.com.ru',
                'akinenkova@smarttechnika.ru',
                'alexey.yurchenko@jackys.com.ru'
            ]
        ]
    ];


    /**
     * Параметры по умолчанию для создания заявок
     * @var array
     */
    private static array $orderParams = [
        'incoming'  => [
            'type' => 'Прием на хранение от поклажедателя',
            'controller' => IncomeController::class
        ],
        'outcoming' => [
            'type' => 'Отгрузка поклажедателю',
            'controller' => OutcomeController::class
        ]
    ];


    public function __construct() {
        $hostname = '{' . env('MAIL_HOST') . ':993/imap/ssl/novalidate-cert}INBOX';
        $username = env('MAIL_USERNAME');
        $password = env('MAIL_PASSWORD');

        $mailConnect = imap_open($hostname, $username, $password) or die('Cannot connect to mail: ' . imap_last_error());

        try {
            self::readEmails($mailConnect);
            self::deleteOldFiles();
        } catch (BadResponseException|Throwable $e) {
            self::notifyAboutError($e, $mailConnect, 0);
        }
    }


    /**
     * Получение писем из почтового ящика
     * @param resource $mailConnect
     */
    private static function readEmails ($mailConnect): void
    {
        $unixNow = time();
        $emails = imap_search($mailConnect, 'ALL');
        if ($emails) {
            foreach ($emails as $mailNumber) {
                self::$orderId = null;
                self::$companyWmsId = null;

                //отсекаем сообщения, сдвинувшие ID всех сообщений ящика во время обработки
                $mailUid = imap_uid($mailConnect, $mailNumber);
                $mailIdOnUid = imap_msgno($mailConnect, $mailUid);
                $checkUid = imap_uid($mailConnect, $mailIdOnUid);
                if ($mailUid != $checkUid) {
                    imap_clearflag_full($mailConnect, $mailNumber, "\\Seen");
                    continue;
                }

                $currentRead = EmailsRead::whereEmailId($mailUid)->first();
                if (!$currentRead) {
                    EmailsRead::create([
                        'email_id'  => $mailUid,
                        'completed' => false
                    ]);
                } else {
                    if ($currentRead->completed) {
                        continue;
                    }
                }

                //на всякий случай дополнительно отсекаем сообщения поступившие после начала обработки
                $header = imap_headerinfo($mailConnect, $mailNumber);
                if ($header->udate > $unixNow) {
                    imap_clearflag_full($mailConnect, $mailNumber, "\\Seen");
                    continue;
                }

                $fromAddr = $header->from[0]->mailbox . "@" . $header->from[0]->host;

                if (!isset(self::$clientsEmails[$fromAddr])) {
                    $currentRead = EmailsRead::whereEmailId($mailUid)->first();
                    $currentRead->completed = true;
                    $currentRead->save();
                    imap_setflag_full($mailConnect, $mailNumber, "\\Seen");
                    continue;
                }

                $structure = imap_fetchstructure($mailConnect, $mailNumber);
                $attachments = [];
                if (isset($structure->parts) && count($structure->parts)) {
                    for ($i = 0; $i < count($structure->parts); $i++) {
                        $attachments[$i] = [
                            'is_attachment' => false,
                            'filename'      => '',
                            'name'          => '',
                            'attachment'    => '',
                            'is_csv'        => false
                        ];

                        if ($structure->parts[$i]->ifdparameters) {
                            foreach($structure->parts[$i]->dparameters as $object) {
                                if(strtolower($object->attribute) == 'filename') {
                                    $attachments[$i]['is_attachment'] = true;
                                    $attachments[$i]['filename'] = imap_utf8($object->value);
                                }
                            }
                        }

                        if ($structure->parts[$i]->ifparameters) {
                            foreach($structure->parts[$i]->parameters as $object) {
                                if(strtolower($object->attribute) == 'name') {
                                    $attachments[$i]['is_attachment'] = true;
                                    $attachments[$i]['name'] = imap_utf8($object->value);
                                }
                            }
                        }

                        if ($attachments[$i]['is_attachment']) {
                            $attachments[$i]['attachment'] = imap_fetchbody($mailConnect, $mailNumber, $i+1);
                            if ($structure->parts[$i]->encoding == 3) {
                                $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                            } else if ($structure->parts[$i]->encoding == 4) {
                                $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                            }
                            if ($structure->parts[$i]->subtype == 'OCTET-STREAM') {
                                $attachments[$i]['is_csv'] = true;
                                $headers = imap_fetchheader($mailConnect, $mailNumber, FT_PREFETCHTEXT);
                                $body = imap_body($mailConnect, $mailNumber);
                                $msgFile = $headers . "\n" . $body;
                                $attachments[$i]['msgFile'] = $msgFile;
                            }
                            if (strtoupper($structure->parts[$i]->subtype) === 'VND.OPENXMLFORMATS-OFFICEDOCUMENT.SPREADSHEETML.SHEET') {
                                $filename = null;
                                if (!empty($structure->parts[$i]->dparameters)) {
                                    foreach ($structure->parts[$i]->dparameters as $param) {
                                        if (strtolower($param->attribute) === 'filename') {
                                            $filename = imap_utf8($param->value);
                                            break;
                                        }
                                    }
                                }
                                if (!$filename && !empty($structure->parts[$i]->parameters)) {
                                    foreach ($structure->parts[$i]->parameters as $param) {
                                        if (strtolower($param->attribute) === 'name') {
                                            $filename = imap_utf8($param->value);
                                            break;
                                        }
                                    }
                                }
                                if ($filename && mb_stripos($filename, 'Транспортная заявка') !== false) {
                                    self::$carrier = self::getCurrier($attachments[$i]);
                                }
                            }
                        }
                    }
                }
                try {
                    self::saveAttachments($attachments, $mailNumber, $fromAddr);
                } catch (BadResponseException|Throwable $e) {
                    self::notifyAboutError($e, $mailConnect, $mailNumber);
                    continue;
                }

                $currentRead = EmailsRead::whereEmailId($mailUid)->first();
                $currentRead->completed = true;
                $currentRead->save();
            }
        }

        imap_close($mailConnect, CL_EXPUNGE);
    }


    /**
     * Получение названия перевозчика заявки на отправление
     * @param array $attachment
     * @return string|null
     */
    private static function getCurrier(array $attachment): string|null
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';
        file_put_contents($tmpFile, $attachment['attachment']);

        $xlsFileData = Excel::toArray(new FlightsImport, $tmpFile);

        self::$carrier = null;
        if (isset($xlsFileData[0])) {
            foreach ($xlsFileData[0] as $arrayData) {
                if (in_array('Транспортная компания (ТК)', $arrayData)) {
                    if ($arrayData[0] === 'Заказчик' && $arrayData[5] === 'Транспортная компания (ТК)') {
                        self::$carrier = $arrayData[8];
                        if (self::$carrier == '#NULL!') {
                            self::$carrier = null;
                        }
                    }
                }
            }
        }

        @unlink($tmpFile);

        return self::$carrier;
    }


    /**
     * Сохранение и обработка вложений из письма
     * @param array $attachments
     * @param int $mailNumber
     * @param string $fromAddr
     */
    private static function saveAttachments(array $attachments, int $mailNumber, string $fromAddr): void
    {
        $attachmentsFolder = '';
        foreach ($attachments as $attachment) {
            if ($attachment['is_attachment']) {
                $filename = $attachment['name'];
                if (empty($filename)) {
                    $filename = $attachment['filename'];
                }

                if (empty($filename)) {
                    $filename = time() . ".dat";
                }

                $folder = storage_path('app/emails/') . date('Y-m-d');
                if (!is_dir($folder)) {
                    mkdir($folder);
                }

                $attachmentsFolder = $folder . '/' . $mailNumber;
                if (!is_dir($attachmentsFolder)) {
                    mkdir($attachmentsFolder);
                }

                $fp = fopen($attachmentsFolder . "/" . $filename, "w+");
                fwrite($fp, $attachment['attachment']);
                fclose($fp);

                if (isset($attachment['msgFile'])) {
                    $fp = fopen($attachmentsFolder . "/Сообщение Email.eml", "w+");
                    fwrite($fp, $attachment['msgFile']);
                    fclose($fp);
                }

                if ($attachment['is_csv']) {
                    $fileRows = explode("\n", $attachment['attachment']);
                    $secondRow = iconv('windows-1251//IGNORE', 'UTF-8//IGNORE', $fileRows[1]);
                    foreach (self::$clientsEmails[$fromAddr]['names'] as $cKey => $cName) {
                        if (str_contains(mb_strtolower($secondRow), $cName)) {
                            self::$companyWmsId = self::$clientsEmails[$fromAddr]['ids'][$cKey];
                        }
                    }
                    self::checkCreateOrder($attachment, $filename, $attachmentsFolder);
                }
            }
        }
        if (self::$orderId) {
            self::saveAllAttachments($attachments, $attachmentsFolder);
        }
    }


    /**
     * Проверка корректности создаваемой заявки поступления/отправления
     * @param array $attachment
     * @param string $filename
     * @param string $attachmentsFolder
     */
    private static function checkCreateOrder(array $attachment, string $filename, string $attachmentsFolder): void
    {
        if (str_starts_with($filename, 'out') || str_starts_with($filename, 'inM')) {
            // inM - так начинаются файлы, которые обозначают ОТПРАВЛЕНИЕ у Элики (означает Интернет магазин 🤷‍♂️)
            self::$orderType = 'outcoming';
        } else if (str_starts_with($filename, 'in')) {
            self::$orderType = 'incoming';
        } else {
            return;
        }

        //принудительно кодируем в UTF-8
        $fileContent = iconv('windows-1251//IGNORE', 'UTF-8//IGNORE', $attachment['attachment']);
        $docDateStart = self::findStrPos($fileContent);
        $docDate = mb_substr($attachment['attachment'], $docDateStart, 10);

        $wms = new WmsCaller();
        $request = new Request();
        $request->merge([
            'company'       => self::$companyWmsId,
            'receipt'       => self::$orderParams[self::$orderType]['type'],
            'file'          => [new UploadedFile($attachmentsFolder . '/' . $filename, $filename)],
            'shipment_date' => $docDate,
            'deliveryDate'  => $docDate
        ]);
        $createResult = (new self::$orderParams[self::$orderType]['controller'])->createOrder($wms, $request);
        $createResult = json_encode($createResult);
        self::confirmCreateOrder($wms, $createResult);
    }


    /**
     * Подтверждение создания заявки поступления/отправления
     * @param WmsCaller $wms
     * @param string $createResult
     */
    private static function confirmCreateOrder(WmsCaller $wms, string $createResult): void
    {
        $timestamp = new DateTime();
        $createResult = json_decode($createResult);
        foreach ($createResult as $res) {
            if ($res->new === false) {
                continue;
            } else {
                $res->deliveryDate = $res->applicationDate;
                $res->shipmentDatePlan = $res->applicationDate;
                $res->carrier = self::$carrier;
                $postResult = (new self::$orderParams[self::$orderType]['controller'])->postStore($wms, $res);
                if ($postResult['id']) {
                    $orderId = $postResult['id'];
                    OrderLog::insert([
                        'wms_id'     => $orderId,
                        'company_id' => $res->depositor->id,
                        'order_type' => self::$orderType,
                        'user_id'    => 0,
                        'unit'       => 'order',
                        'action'     => 'create',
                        'value'      => $timestamp->format('d.m.Y H:i:s'),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp
                    ]);
                    $orderFile = File::whereTempFile($res->tempFile)->first();
                    if ($orderFile) {
                        $orderFile->document_id = $postResult['id'];
                        $orderFile->temp_file = NULL;
                        $orderFile->save();
                    }
                    self::$orderId = $orderId;
                }
            }
        }
    }


    /**
     * Сохранение всех вложений письма в заявку
     * @param array $attachments
     * @param string $attachmentsFolder
     */
    private static function saveAllAttachments(array $attachments, string $attachmentsFolder): void
    {
        $companyId = Company::whereWmsId(self::$companyWmsId)->pluck('id')->first();

        foreach ($attachments as $attachment) {
            if (isset($attachment['msgFile'])) {
                $filename = 'Сообщение Email.eml';
            } else {
                $filename = $attachment['name'];
                if (empty($filename)) {
                    $filename = $attachment['filename'];
                }
            }
            if ($filename) {
                $time = time();
                $fileObj = new UploadedFile($attachmentsFolder . '/' . $filename, $filename);
                $savedFile = File::uploadFile($fileObj, self::$orderType, $companyId, $time, self::$orderId, null, true);
                $storagePath = storage_path('app/files/' . self::$orderType .'/');
                $storageFileName = $savedFile->id . '_' . $companyId . '_' . $time . '.' . $savedFile->extension;
                $fp = fopen($storagePath . "/" . $storageFileName, "w+");
                fwrite($fp, $attachment['attachment']);
                fclose($fp);
            }
        }
    }


    /**
     * Найти координаты нужного по номеру вхождения в строку
     * @param string $string - сама строка, в которой выполняется поиск
     * @return int|NULL
     */
    private static function findStrPos(string $string): ?int
    {
        $lastPos = 0;
        $count = 0;
        $foundPosition = null;

        while (($lastPos = mb_strpos($string, ';', $lastPos))!== false) {
            $lastPos = $lastPos + mb_strlen(';');
            ++$count;
            if ($count == 3) {
                $foundPosition = $lastPos;
                break;
            }
        }

        return $foundPosition;
    }


    /**
     * Удаление старых файлов (более недели) из папки /storage/app/emails/
     */
    private static function deleteOldFiles(): void
    {
        $weekAgo = date('Y-m-d', strtotime("-8 days"));
        $folder = storage_path('app/emails/') . $weekAgo;
        if (is_dir($folder)) {
            self::rrmdir($folder);
        }
    }


    /**
     * Удаление всего содержимого папки
     * @param string $folder
     */
    private static function rrmdir(string $folder): void
    {
        if (is_dir($folder)) {
            $objects = scandir($folder);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($folder . DIRECTORY_SEPARATOR . $object) && !is_link($folder . "/" . $object)) {
                        self::rrmdir($folder . DIRECTORY_SEPARATOR . $object);
                    } else {
                        unlink($folder . DIRECTORY_SEPARATOR . $object);
                    }
                }
            }
            rmdir($folder);
        }
    }


    /**
     * Оповещение об ошибках обработки письма + установка флага "Не прочитано"
     * @param object $e
     * @param object $mailConnect
     * @param int $mailNumber
     */
    private static function notifyAboutError($e, $mailConnect, int $mailNumber): void
    {
        $setUnread = true;
        if (str_contains($e->getMessage(), 'уже существует') || str_contains($e->getMessage(), 'уже существует')) {
            $setUnread = false;
            $mailUid = imap_uid($mailConnect, $mailNumber);
            $currentRead = EmailsRead::whereEmailId($mailUid)->first();
            $currentRead->completed = true;
            $currentRead->save();

            self::notifyClients($e->getMessage());
        }
        $message = 'Письмо #' . $mailNumber . ': ' . $e->getMessage();
        TelegramSender::sendMessage('mail_error', $message);
        if ($setUnread) {
            imap_clearflag_full($mailConnect, $mailNumber, "\\Seen");
        }
    }

    /**
     * Оповещение клиентов об ошибке создания заявки
     * @param string $errorText
     * @return void
     */
    private static function notifyClients(string $errorText): void
    {
        $errorMessage = self::extractErrorMessage($errorText);
        $russianOrders = [
            'incoming' => 'поступления',
            'outcoming' => 'отправления'
        ];
        $orderType = $russianOrders[self::$orderType];
        $mailer = App::make(AppMailer::class);

        foreach (self::$clientsEmails as $client) {
            if (in_array(self::$companyWmsId, $client['ids'])) {
                foreach ($client['answer'] as $email) {
                    $mailer->sendOrderError($email, $errorMessage, $orderType);
                }
            }
        }
    }

    /**
     * Извлекает суть из текста ошибки.
     *
     * @param string $text
     * @return string|null
     */
    private static function extractErrorMessage(string $text): ?string
    {
        $pos = strrpos($text, ':');
        if ($pos !== false) {
            return trim(substr($text, $pos + 1));
        }

        return null;
    }
}
