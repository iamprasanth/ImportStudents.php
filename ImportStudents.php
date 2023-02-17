<?php

namespace SHLR\Console\Commands;

use Illuminate\Console\Command;
use Orchestra\Parser\Xml\Facade as XmlParser;
use SHLR\Models\Students;
use SHLR\Models\User;
use SHLR\Models\StudyProgram;
use SHLR\Models\Modules;
use SHLR\Models\StudentModules;
use SHLR\Models\CronJobLog;
use Carbon\Carbon as Carbon;
use File;
use Config;
use Mail;
use Storage;

class ImportStudents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:students';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This console command will be automatically run every month to import students from XML file placed in specified directory';

    private $xmlPath;
    private $archiveFolderPath;
    private $archiveFileName;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $xmlPath = "/import/import.xml";
        $archiveFolderPath = '/import/archive/';
        $archiveFileName = Carbon::now()->format('mdY').'_import.xml';
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $DEBUG_IMPORT = "FALSE";
        if (!is_null(Config::get('constants.DEBUG_IMPORT'))) {
            $DEBUG_IMPORT = Config::get('constants.DEBUG_IMPORT');
        }
        try {
            try {
                $this->message('Starting Import', 'info');
                $this->message('Loading XML: ' . storage_path() . $this-xmlPath);
                $xml = simplexml_load_file($this-xmlPath);
            } catch (\ErrorException $exception) {
                $this->message('Unable to load XML file', 'error');
                $this->message($exception, 'error');
                CronJobLog::insert([
                    'import_for' => Carbon::now()->format('M-Y'),
                    'status' => 0,
                    'message' => $exception,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $this->sendFailureMail();
            }
            $xmlArray = (array)$xml;
            if ($this->xmlFileValidation($xmlArray)) {
                $row = 0;
                if ($DEBUG_IMPORT == "PROGRESSBAR") {
                    $progressBar = $this->output->createProgressBar(sizeof($xmlArray['student']));
                    $progressBar->start();
                }
                $this->message('Count: ' . sizeof($xmlArray['student']), "info");
                foreach ($xmlArray['student'] as $student) {
                    $row++;
                    if ($DEBUG_IMPORT == "PROGRESSBAR") {
                        $this->output->write("<info> Processing Row => </info>");
                        $this->output->write("<info>$row</info>");
                    }
                    $student = (array)$student;
                    $data = [
                        'lastname' => $student['name'],
                        'firstname' => $student['vorname'],
                        'matriculation_number' => $student['matrikelnummer'],
                        'city' => $student['buergerort'],
                        'dob' => Carbon::parse($student['geburtstag'])->format('Y-m-d')
                    ];
                    $data['email'] = ($student['benutzer'] == "") ? null : $student['benutzer'].'@student.shlr.ch';
                    $data['password'] = ($student['passwort'] == "") ? null : bcrypt($student['passwort']);
                    $studentExists = User::where('xml_id', $student['ID'])
                                         ->where('role', 3)
                                         ->where('is_deleted', 0)
                                         ->first();
                    if ($studentExists) {
                        $studentId = $studentExists->id;
                        $this->message('Row: ' . $row . ', Student with ID: ' . $studentId .  ' already exists');
                        User::where('id', $studentId)->update($data);
                        $this->message('Updated User details, ID: ' . $studentId, "info");
                    } else {
                        $data['xml_id'] = $student['ID'];
                        $data['role'] = 3;
                        $data['is_active'] = 1;
                        $data['created_at'] = Carbon::now();
                        $data['updated_at'] = Carbon::now();
                        $studentId = User::insertGetId($data);
                        $this->message('Row: ' . $row . ', Inserted New User, ID: ' . $studentId, "info");
                    }
                    if ($studentId) {
                        $programId = StudyProgram::where('title', $student['studiengang'])
                                                  ->where('is_active', 1)
                                                  ->where('is_deleted', 0)
                                                  ->value('id');
                        if ($programId) {
                            if (!Students::where('student_id', $studentId)->where('is_deleted', 0)->exists()) {
                                $data = [
                                    'student_id' => $studentId,
                                    'program_id' => $programId,
                                    'is_active' => 1,
                                    'created_at' => Carbon::now(),
                                    'updated_at' => Carbon::now(),
                                ];
                                $studentInfoId = Students::insertGetId($data);
                                $this->message('Inserted Data in Student Info, ID: ' . $studentInfoId, "info");
                            } else {
                                $studentInfoId = Students::where('student_id', $studentId)->update(['program_id' => $programId]);
                                $this->message('Updated Student Info, ID: ' . $studentInfoId, "info");
                            }
                            if (!$studentExists) {
                                $modules = Modules::where('course_study_id', $programId)
                                                  ->where('is_active', 1)
                                                  ->where('is_deleted', 0)
                                                  ->orderBy('sort', 'ASC')
                                                  ->get()->toArray();
                                foreach ($modules as $module) {
                                    $studentModuleExists = StudentModules::where('student_id', $studentId)
                                                                         ->where('module_id', $module['id'])
                                                                         ->where('is_active', 1)
                                                                         ->where('is_deleted', 0)
                                                                         ->exists();
                                    if (!$studentModuleExists) {
                                        $data = [
                                            'student_id' => $studentId,
                                            'module_id' => $module['id'],
                                            'created_at' => Carbon::now(),
                                            'updated_at' => Carbon::now()
                                        ];
                                        $studentModuleId = StudentModules::insertGetId($data);
                                        $this->message('Inserting Student Modules, ID: ' . $studentModuleId, "info");
                                    }
                                }
                            }
                        }
                    }
                    if ($DEBUG_IMPORT == "PROGRESSBAR") {
                        $progressBar->advance();
                    }
                }
                $this->message('Import Completed', "info");
                CronJobLog::insert([
                    'import_for' => Carbon::now()->format('M-Y'),
                    'status' => 1,
                    'message' => 'Successfully Imported',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                if ($DEBUG_IMPORT == "PROGRESSBAR") {
                    $progressBar->finish();
                }
                $this->sendSuccessMail();
                // Move import file to archive folder
                if (!is_dir($this->archiveFolderPath)) {
                    mkdir($this->archiveFolderPath, 0777, true);
                }
                Storage::move($this-xmlPath, $this->archiveFolderPath . $this->archiveFileName);
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            CronJobLog::insert([
                'import_for' => Carbon::now()->format('M-Y'),
                'status' => 0,
                'message' => $exception,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $this->sendFailureMail();
        } catch (\ErrorException $exception) {
            CronJobLog::insert([
                'import_for' => Carbon::now()->format('M-Y'),
                'status' => 0,
                'message' => $exception,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $this->sendFailureMail();
        }
    }

    /*
     * Function for sending mail
     */
    public function xmlFileValidation($xmlArray)
    {
        $this->message('Validating XML', "info");
        if (!array_key_exists("student", $xmlArray)) {
            CronJobLog::insert([
                'import_for' => Carbon::now()->format('M-Y'),
                'status' => 0,
                'message' => 'No student details',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $this->message('Error: No student details', "error");
            $this->sendFailureMail();

            return false;
        }
        $row = 0;
        foreach ($xmlArray['student'] as $student) {
            $row++;
            $student = (array)$student;
            $programId = StudyProgram::where('title', $student['studiengang'])
                                     ->where('is_active', 1)
                                     ->where('is_deleted', 0)
                                     ->value('id');
            if (is_null($programId)) {
                CronJobLog::insert([
                    'import_for' => Carbon::now()->format('M-Y'),
                    'status' => 0,
                    'message' => 'Invalid study program - Row:' . $row,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $this->message('Error: Invalid study program  - Row:' . $row, "error");
                $this->sendFailureMail();

                return false;
            }
            if (array_key_exists('benutzer', $student)) {
                unset($student['benutzer']);
            }
            if (in_array("", $student)) {
                CronJobLog::insert([
                    'import_for' => Carbon::now()->format('M-Y'),
                    'status' => 0,
                    'message' => 'Empty entries in student details - Row:' . $row,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $this->message('Warning: Empty entries in student details - Row:' . $row, "error");
                $this->sendFailureMail();
            }
        }

        return true;
    }

    /*
     * Function for sending email to Admin when importing failed
     */
    public function sendFailureMail()
    {
        Mail::send([], [], function ($message) {
            $message->to(Config::get('constants.SPAWOZ_MAILS'))
                    ->subject('SHLR Student Import Failed')
                    ->setBody('Database Updation for the month '.Carbon::now()->format('M').'<b> Failed</b>.', 'text/html');
        });
    }

    /*
     * Function for sending email to Admin and saving xml in archive after successfull import
     */
    public function sendSuccessMail()
    {
        Mail::send([], [], function ($message) {
            $message->to(Config::get('constants.SPAWOZ_MAILS'))
                    ->subject('SHLR Student Import Successfull')
                    ->setBody('Database <b>successfully</b> Updated for the month '.Carbon::now()->format('M').'.', 'text/html');
        });
    }

    /*
     * Function to output messages to console
     */
    public function message($message, $type="line")
    {
        if (!is_null(Config::get('constants.DEBUG_IMPORT'))) {
            if (Config::get('constants.DEBUG_IMPORT') == 'TEXT') {
                switch ($type)
                {
                    case "info":
                        $this->info($message);
                        break;
                    case "error":
                        $this->error($message);
                        break;
                    case "comment":
                        $this->comment($message);
                        break;
                    case "question":
                        $this->question($message);
                        break;
                    default:
                        $this->question($message);
                }
                return true;
            }
        }
        return false;
    }
}
