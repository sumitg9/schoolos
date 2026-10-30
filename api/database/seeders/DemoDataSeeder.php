<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\AssetLifecycleEvent;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLeaveRequest;
use App\Models\EmployeeSalaryStructure;
use App\Models\ExamSchedule;
use App\Models\ExamScheduleSubject;
use App\Models\FeeCharge;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Guardian;
use App\Models\HomeworkAssignment;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\NotificationDelivery;
use App\Models\OperationalExpense;
use App\Models\PayrollRecord;
use App\Models\PortalActivationCode;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAsset;
use App\Models\SchoolClass;
use App\Models\SchoolEvent;
use App\Models\SchoolNotice;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentExamMark;
use App\Models\StudentMedicalRecord;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TimetableSlot;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Tenancy\SchoolContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'DemoSchool2026!';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Demo data seeding is disabled in production.');

            return;
        }

        $this->call(AccessControlSeeder::class);
        $school = School::query()->firstOrCreate(
            ['slug' => 'demo-school'],
            ['name' => 'Demo School', 'status' => 'active'],
        );

        SchoolContext::set($school->id);
        try {
            $this->seedSchool($school);
        } finally {
            SchoolContext::set(null);
        }

        $this->command?->info('Demo data is ready for Demo School.');
        $this->command?->line('Password for all demo users: '.self::DEMO_PASSWORD);
        $this->command?->line('Admin: admin.demo@example.test | Teacher: teacher.demo@example.test');
        $this->command?->line('Parent: parent.demo@example.test | Student: student.demo@example.test');
        $this->command?->line('Student activation code: DEMO-STUDENT-ACTIVATE-2026');
        $this->command?->warn('These fixed credentials and records are for non-production testing only.');
    }

    private function seedSchool(School $school): void
    {
        $admin = $this->demoUser('admin.demo@example.test', 'Demo Administrator', 'school-admin', $school);
        $teacherUser = $this->demoUser('teacher.demo@example.test', 'Demo Teacher', 'teacher', $school);
        $parentUser = $this->demoUser('parent.demo@example.test', 'Demo Parent', 'parent', $school);
        $studentUser = $this->demoUser('student.demo@example.test', 'Demo Student', 'student', $school);
        $staffUser = $this->demoUser('staff.demo@example.test', 'Demo Non-teaching Staff', 'operations-manager', $school);
        $accountant = $this->demoUser('accountant.demo@example.test', 'Demo Accountant', 'accountant', $school);
        $operations = $this->demoUser('operations.demo@example.test', 'Demo Operations Manager', 'operations-manager', $school);
        $hrManager = $this->demoUser('hr.demo@example.test', 'Demo HR Manager', 'hr-manager', $school);

        SchoolSetting::query()->firstOrCreate(
            ['school_id' => $school->id, 'singleton_key' => 'school'],
            [
                'name' => $school->name,
                'address' => '100 Example Road, Demo City',
                'phone' => '+91 90000 00000',
                'email' => 'office@demo-school.example.test',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
            ],
        );
        $session = AcademicSession::query()->firstOrCreate(
            ['name' => 'DEMO 2026-2027'],
            ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'is_active' => true],
        );
        AcademicSession::query()->where('is_active', true)->whereKeyNot($session->id)->update(['is_active' => false]);

        $class = SchoolClass::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'name' => 'Demo Class 5'],
            ['capacity' => 30, 'room_number' => 'D-105'],
        );
        $section = Section::query()->firstOrCreate(
            ['school_class_id' => $class->id, 'name' => 'A'],
            ['capacity' => 30, 'room_number' => 'D-105'],
        );

        $teacher = Employee::query()->firstOrCreate(
            ['email' => 'teacher.employee@demo-school.example.test'],
            [
                'employee_id' => 'DEMO-EMP-001',
                'user_id' => $teacherUser->id,
                'first_name' => 'Jordan',
                'last_name' => 'Teacher',
                'phone' => '+91 90000 00001',
                'department' => 'Academics',
                'designation' => 'Class Teacher',
                'employment_type' => 'full_time',
                'is_teaching_staff' => true,
                'joining_date' => '2022-06-01',
                'status' => 'active',
                'qualification' => 'B.Ed.',
                'experience_years' => 8,
                'created_by' => $admin->id,
            ],
        );
        if (! $teacher->employee_id) {
            $teacher->forceFill(['employee_id' => 'DEMO-EMP-001'])->save();
        }

        $driver = Employee::query()->firstOrCreate(
            ['email' => 'driver.employee@demo-school.example.test'],
            [
                'employee_id' => 'DEMO-EMP-002',
                'first_name' => 'Casey',
                'last_name' => 'Driver',
                'phone' => '+91 90000 00002',
                'department' => 'Transport',
                'designation' => 'Driver',
                'employment_type' => 'full_time',
                'is_teaching_staff' => false,
                'joining_date' => '2023-01-15',
                'status' => 'active',
                'created_by' => $admin->id,
            ],
        );
        if (! $driver->employee_id) {
            $driver->forceFill(['employee_id' => 'DEMO-EMP-002'])->save();
        }

        $staff = Employee::query()->firstOrCreate(
            ['email' => 'staff.employee@demo-school.example.test'],
            [
                'employee_id' => 'DEMO-EMP-003',
                'user_id' => $staffUser->id,
                'first_name' => 'Demo Non-teaching',
                'last_name' => 'Staff',
                'phone' => '+91 90000 00004',
                'department' => 'Administration',
                'designation' => 'Administrative Assistant',
                'employment_type' => 'full_time',
                'is_teaching_staff' => false,
                'joining_date' => '2024-06-01',
                'status' => 'active',
                'created_by' => $admin->id,
            ],
        );
        if (! $staff->employee_id) {
            $staff->forceFill(['employee_id' => 'DEMO-EMP-003'])->save();
        }

        $subjects = collect([
            ['name' => 'Demo Mathematics', 'code' => 'DEMO-MATH', 'type' => 'compulsory'],
            ['name' => 'Demo English', 'code' => 'DEMO-ENG', 'type' => 'compulsory'],
            ['name' => 'Demo Science', 'code' => 'DEMO-SCI', 'type' => 'compulsory'],
        ])->map(fn (array $attributes) => Subject::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'code' => $attributes['code']],
            $attributes,
        ));
        $class->subjects()->syncWithoutDetaching($subjects->pluck('id')->all());

        $students = collect([
            ['admission_number' => 'DEMO-0001', 'first_name' => 'Avery', 'last_name' => 'Student', 'email' => 'student.demo@example.test'],
            ['admission_number' => 'DEMO-0002', 'first_name' => 'Riley', 'last_name' => 'Student', 'email' => 'riley.student@demo-school.example.test'],
            ['admission_number' => 'DEMO-0003', 'first_name' => 'Quinn', 'last_name' => 'Student', 'email' => 'quinn.student@demo-school.example.test'],
        ])->map(function (array $attributes) use ($admin, $studentUser) {
            $student = Student::query()->firstOrCreate(
                ['admission_number' => $attributes['admission_number']],
                [
                    'first_name' => $attributes['first_name'],
                    'last_name' => $attributes['last_name'],
                    'date_of_birth' => '2016-05-10',
                    'gender' => 'not_specified',
                    'admission_date' => '2026-04-01',
                    'phone' => '+91 90000 00010',
                    'email' => $attributes['email'],
                    'address' => '10 Example Street, Demo City',
                    'status' => 'active',
                    'created_by' => $admin->id,
                ],
            );
            if ($attributes['admission_number'] === 'DEMO-0001' && ! $student->portal_user_id) {
                $student->forceFill(['portal_user_id' => $studentUser->id])->save();
            }

            return $student;
        });

        $guardian = Guardian::query()->firstOrCreate(
            ['email' => 'parent.demo@example.test'],
            [
                'first_name' => 'Taylor',
                'last_name' => 'Guardian',
                'phone' => '+91 90000 00003',
                'occupation' => 'Engineer',
                'address' => '10 Example Street, Demo City',
            ],
        );
        if (! $guardian->portal_user_id) {
            $guardian->forceFill(['portal_user_id' => $parentUser->id])->save();
        }
        foreach ($students as $index => $student) {
            $student->guardians()->syncWithoutDetaching([
                $guardian->id => [
                    'school_id' => $school->id,
                    'relationship' => $index === 0 ? 'Parent' : 'Guardian',
                    'is_primary' => true,
                ],
            ]);
        }

        StudentMedicalRecord::query()->firstOrCreate(
            ['student_id' => $students[0]->id],
            [
                'medical_notes' => 'Synthetic test record. No known restrictions.',
                'allergies' => 'None recorded',
                'emergency_notes' => 'Contact demo guardian.',
                'updated_by' => $admin->id,
            ],
        );

        $enrollments = $students->values()->map(function (Student $student, int $index) use ($session, $class, $section) {
            return StudentEnrollment::query()->firstOrCreate(
                ['student_id' => $student->id, 'academic_session_id' => $session->id],
                [
                    'school_class_id' => $class->id,
                    'section_id' => $section->id,
                    'roll_number' => (string) ($index + 1),
                    'enrolled_on' => '2026-04-01',
                    'status' => 'active',
                ],
            );
        });

        foreach ($subjects as $index => $subject) {
            TeacherAssignment::query()->firstOrCreate(
                [
                    'employee_id' => $teacher->id,
                    'academic_session_id' => $session->id,
                    'school_class_id' => $class->id,
                    'section_id' => $section->id,
                    'subject_id' => $subject->id,
                ],
                ['is_class_teacher' => $index === 0, 'status' => 'active'],
            );
        }

        foreach ($enrollments as $index => $enrollment) {
            AttendanceRecord::query()->firstOrCreate(
                ['student_enrollment_id' => $enrollment->id, 'attendance_date' => '2026-10-28'],
                [
                    'status' => $index === 1 ? 'late' : 'present',
                    'remarks' => $index === 1 ? 'Arrived after assembly.' : null,
                    'recorded_by' => $admin->id,
                ],
            );
        }

        $exam = ExamSchedule::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'school_class_id' => $class->id, 'title' => 'Demo Midterm Examination'],
            [
                'starts_on' => '2026-10-12',
                'ends_on' => '2026-10-14',
                'status' => 'published',
                'results_status' => 'published',
                'grading_scale' => [
                    ['label' => 'A+', 'minimum_percentage' => 90],
                    ['label' => 'A', 'minimum_percentage' => 80],
                    ['label' => 'B', 'minimum_percentage' => 70],
                    ['label' => 'C', 'minimum_percentage' => 60],
                    ['label' => 'D', 'minimum_percentage' => 50],
                    ['label' => 'F', 'minimum_percentage' => 0],
                ],
                'results_approved_by' => $admin->id,
                'results_approved_at' => now()->subDays(14),
                'results_published_by' => $admin->id,
                'results_published_at' => now()->subDays(13),
                'created_by' => $admin->id,
            ],
        );
        foreach ($subjects as $index => $subject) {
            $examSubject = ExamScheduleSubject::query()->firstOrCreate(
                ['exam_schedule_id' => $exam->id, 'subject_id' => $subject->id],
                [
                    'exam_date' => now()->subDays(18 - $index)->toDateString(),
                    'starts_at' => '09:00:00',
                    'duration_minutes' => 90,
                    'maximum_marks' => 100,
                    'passing_marks' => 35,
                ],
            );
            foreach ($enrollments as $studentIndex => $enrollment) {
                StudentExamMark::query()->firstOrCreate(
                    ['exam_schedule_subject_id' => $examSubject->id, 'student_enrollment_id' => $enrollment->id],
                    [
                        'marks_obtained' => 72 + (($studentIndex + $index) % 25),
                        'remarks' => 'Synthetic demo result.',
                        'entered_by' => $teacherUser->id,
                    ],
                );
            }
        }

        HomeworkAssignment::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'school_class_id' => $class->id, 'title' => 'Demo fractions practice'],
            [
                'section_id' => $section->id,
                'subject_id' => $subjects[0]->id,
                'employee_id' => $teacher->id,
                'description' => 'Complete exercises 1-10 in the synthetic demo workbook.',
                'assigned_on' => '2026-10-26',
                'due_on' => '2026-11-02',
                'status' => 'published',
                'created_by' => $teacherUser->id,
            ],
        );

        foreach ([['weekday' => 1, 'period' => 1, 'subject' => $subjects[0], 'start' => '09:00', 'end' => '09:45'],
            ['weekday' => 1, 'period' => 2, 'subject' => $subjects[1], 'start' => '09:50', 'end' => '10:35'],
            ['weekday' => 2, 'period' => 1, 'subject' => $subjects[2], 'start' => '09:00', 'end' => '09:45']] as $slot) {
            TimetableSlot::query()->firstOrCreate(
                [
                    'academic_session_id' => $session->id,
                    'section_id' => $section->id,
                    'weekday' => $slot['weekday'],
                    'period_number' => $slot['period'],
                ],
                [
                    'school_class_id' => $class->id,
                    'subject_id' => $slot['subject']->id,
                    'employee_id' => $teacher->id,
                    'starts_at' => $slot['start'],
                    'ends_at' => $slot['end'],
                    'room_number' => 'D-105',
                ],
            );
        }

        foreach ([
            ['title' => 'Demo School Open Day', 'audience' => 'all'],
            ['title' => 'Demo Parent Meeting', 'audience' => 'parents'],
        ] as $notice) {
            SchoolNotice::query()->firstOrCreate(
                ['title' => $notice['title']],
                [
                    'body' => 'Synthetic demonstration notice. No real event is scheduled.',
                    'audience' => $notice['audience'],
                    'status' => 'published',
                    'publish_at' => now()->subDay(),
                    'expires_at' => now()->addMonth(),
                    'created_by' => $admin->id,
                ],
            );
        }

        $feeStructure = FeeStructure::query()->firstOrCreate(
            [
                'academic_session_id' => $session->id,
                'school_class_id' => $class->id,
                'name' => 'Demo Annual Fees',
            ],
            ['late_fee_amount' => 50, 'status' => 'active', 'created_by' => $admin->id],
        );
        foreach ([['Tuition fee', 12000], ['Technology fee', 1200], ['Activities fee', 800]] as [$description, $amount]) {
            FeeStructureItem::query()->firstOrCreate(
                ['fee_structure_id' => $feeStructure->id, 'description' => $description],
                ['amount' => $amount],
            );
        }

        foreach ($enrollments as $index => $enrollment) {
            $invoice = FeeInvoice::query()->firstOrCreate(
                ['fee_structure_id' => $feeStructure->id, 'student_id' => $enrollment->student_id],
                [
                    'invoice_number' => sprintf('DEMO-INV-%04d', $index + 1),
                    'student_enrollment_id' => $enrollment->id,
                    'due_on' => now()->subDays(5)->toDateString(),
                    'concession_amount' => $index === 0 ? 500 : 0,
                    'concession_reason' => $index === 0 ? 'Demo merit concession' : null,
                    'late_fee_amount' => 50,
                    'created_by' => $admin->id,
                ],
            );
            foreach ($feeStructure->items as $item) {
                FeeCharge::query()->firstOrCreate(
                    ['fee_invoice_id' => $invoice->id, 'description' => $item->description],
                    [
                        'student_id' => $enrollment->student_id,
                        'amount' => $item->amount,
                        'due_on' => $invoice->due_on,
                        'status' => 'posted',
                        'created_by' => $admin->id,
                    ],
                );
            }
            if ($index === 0) {
                FeePayment::query()->firstOrCreate(
                    ['receipt_number' => 'DEMO-REC-0001'],
                    [
                        'student_id' => $enrollment->student_id,
                        'fee_invoice_id' => $invoice->id,
                        'amount' => 5000,
                        'paid_at' => now()->subDays(2),
                        'method' => 'cash',
                        'reference' => 'DEMO-CASH-001',
                        'remarks' => 'Synthetic partial fee payment.',
                        'recorded_by' => $accountant->id,
                    ],
                );
            }
        }

        $this->seedPortalActivation($students[2]->id, $admin->id);
        $this->seedTransport($students, $driver);
        $this->seedLibrary($students[0]->id, $admin->id);
        $this->seedInventory($admin->id);
        $this->seedPayrollAndLeave($teacher, $hrManager, $admin);
        $this->seedOperations($admin, $operations, $accountant, $teacher);
        $this->seedNotification($admin->id);
        $this->seedAudit($admin, $school);
    }

    private function seedPortalActivation(int $studentId, int $createdBy): void
    {
        $code = 'DEMO-STUDENT-ACTIVATE-2026';
        PortalActivationCode::query()->firstOrCreate(
            ['code_hash' => hash('sha256', Str::upper($code))],
            [
                'target_type' => 'student',
                'target_id' => $studentId,
                'created_by' => $createdBy,
                'expires_at' => now()->addDays(30),
            ],
        );
    }

    private function seedTransport($students, Employee $driver): void
    {
        $vehicle = TransportVehicle::query()->firstOrCreate(
            ['registration_number' => 'DEMO-BUS-001'],
            [
                'description' => 'Synthetic demo 12-seat school bus',
                'capacity' => 12,
                'driver_employee_id' => $driver->id,
                'status' => 'active',
            ],
        );
        $route = TransportRoute::query()->firstOrCreate(
            ['name' => 'Demo North Route'],
            [
                'stops' => 'Demo School, North Park, Example Road',
                'monthly_fee' => 750,
                'vehicle_id' => $vehicle->id,
                'status' => 'active',
            ],
        );
        foreach ($students as $index => $student) {
            TransportAssignment::query()->firstOrCreate(
                ['student_id' => $student->id, 'status' => 'active'],
                [
                    'transport_route_id' => $route->id,
                    'pickup_point' => 'Demo Stop '.($index + 1),
                    'starts_on' => '2026-04-01',
                ],
            );
        }
    }

    private function seedLibrary(int $studentId, int $issuedBy): void
    {
        $books = [
            ['title' => 'Demo Science Reader', 'isbn' => 'DEMO-ISBN-0001', 'copies' => 2, 'available' => 1],
            ['title' => 'Demo Mathematics Workbook', 'isbn' => 'DEMO-ISBN-0002', 'copies' => 3, 'available' => 3],
        ];
        foreach ($books as $bookData) {
            $book = LibraryBook::query()->firstOrCreate(
                ['isbn' => $bookData['isbn']],
                [
                    'title' => $bookData['title'],
                    'author' => 'Demo Author',
                    'category' => 'Education',
                    'total_copies' => $bookData['copies'],
                    'available_copies' => $bookData['available'],
                ],
            );
            if ($bookData['isbn'] === 'DEMO-ISBN-0001') {
                LibraryLoan::query()->firstOrCreate(
                    ['book_id' => $book->id, 'student_id' => $studentId, 'status' => 'issued'],
                    [
                        'issued_on' => now()->subDays(3)->toDateString(),
                        'due_on' => now()->addDays(11)->toDateString(),
                        'issued_by' => $issuedBy,
                    ],
                );
            }
        }
    }

    private function seedInventory(int $recordedBy): void
    {
        $item = InventoryItem::query()->firstOrCreate(
            ['sku' => 'DEMO-STATIONERY-001'],
            [
                'name' => 'Demo exercise books',
                'category' => 'Stationery',
                'unit' => 'pack',
                'quantity' => 8,
                'reorder_level' => 3,
            ],
        );
        foreach ([
            ['type' => 'received', 'quantity' => 10, 'description' => 'Demo opening stock'],
            ['type' => 'issued', 'quantity' => 2, 'description' => 'Demo classroom issue'],
        ] as $transaction) {
            InventoryTransaction::query()->firstOrCreate(
                ['inventory_item_id' => $item->id, 'description' => $transaction['description']],
                $transaction + ['recorded_by' => $recordedBy],
            );
        }
    }

    private function seedPayrollAndLeave(Employee $employee, User $hrManager, User $admin): void
    {
        $structure = EmployeeSalaryStructure::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'effective_from' => '2026-01-01'],
            [
                'base_salary' => 45000,
                'allowances' => [['name' => 'Housing allowance', 'amount' => '5000.00']],
                'deductions' => [['name' => 'Tax', 'amount' => '2500.00']],
                'created_by' => $hrManager->id,
            ],
        );
        unset($structure);

        PayrollRecord::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'pay_year' => 2026, 'pay_month' => 9],
            [
                'base_salary' => 45000,
                'allowances' => 5000,
                'deductions' => 2500,
                'net_amount' => 47500,
                'allowance_items' => [['name' => 'Housing allowance', 'amount' => '5000.00']],
                'deduction_items' => [['name' => 'Tax', 'amount' => '2500.00']],
                'status' => 'paid',
                'paid_at' => now()->subDays(20),
                'reference' => 'DEMO-SALARY-PAYMENT-001',
                'processed_by' => $admin->id,
                'created_by' => $hrManager->id,
                'approved_by' => $admin->id,
                'approved_at' => now()->subDays(22),
                'slip_number' => 'DEMO-SAL-202609-0001',
            ],
        );
        foreach ([
            ['leave_type' => 'annual', 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-12', 'status' => 'pending'],
            ['leave_type' => 'sick', 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-11', 'status' => 'approved'],
        ] as $index => $leave) {
            EmployeeLeaveRequest::query()->firstOrCreate(
                ['employee_id' => $employee->id, 'starts_on' => $leave['starts_on']],
                $leave + [
                    'reason' => $index === 0 ? 'Demo pending leave request.' : 'Synthetic approved leave.',
                    'reviewed_by' => $index === 0 ? null : $admin->id,
                    'reviewed_at' => $index === 0 ? null : now()->subDays(45),
                    'review_note' => $index === 0 ? null : 'Approved for demo.',
                ],
            );
        }
    }

    private function seedOperations(User $admin, User $operations, User $accountant, Employee $custodian): void
    {
        $event = SchoolEvent::query()->firstOrCreate(
            ['name' => 'Demo Community Science Fair'],
            [
                'category' => 'Community',
                'description' => 'Synthetic event to exercise budgets and linked expenses.',
                'venue' => 'Demo School Hall',
                'starts_on' => '2026-11-20',
                'ends_on' => '2026-11-20',
                'budget' => 3000,
                'status' => 'planned',
                'created_by' => $operations->id,
            ],
        );
        foreach ([
            ['description' => 'Demo science fair materials', 'category' => 'Supplies', 'amount' => 3200, 'status' => 'approved'],
            ['description' => 'Demo science fair refreshments', 'category' => 'Catering', 'amount' => 2400, 'status' => 'pending'],
            ['description' => 'Demo office stationery', 'category' => 'Office', 'amount' => 450, 'status' => 'paid'],
        ] as $index => $expenseData) {
            OperationalExpense::query()->firstOrCreate(
                ['description' => $expenseData['description']],
                [
                    'event_id' => $index < 2 ? $event->id : null,
                    'category' => $expenseData['category'],
                    'vendor' => 'Demo Supplies Ltd.',
                    'spent_on' => now()->subDays($index + 1)->toDateString(),
                    'amount' => $expenseData['amount'],
                    'reference' => 'DEMO-EXP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'status' => $expenseData['status'],
                    'review_note' => $expenseData['status'] === 'approved' ? 'Approved for demo.' : null,
                    'submitted_by' => $accountant->id,
                    'approved_by' => in_array($expenseData['status'], ['approved', 'paid'], true) ? $admin->id : null,
                    'approved_at' => in_array($expenseData['status'], ['approved', 'paid'], true) ? now()->subDays(1) : null,
                    'paid_by' => $expenseData['status'] === 'paid' ? $operations->id : null,
                    'paid_at' => $expenseData['status'] === 'paid' ? now() : null,
                    'payment_reference' => $expenseData['status'] === 'paid' ? 'DEMO-BANK-001' : null,
                ],
            );
        }

        $asset = SchoolAsset::query()->firstOrCreate(
            ['asset_tag' => 'DEMO-PROJECTOR-001'],
            [
                'name' => 'Demo classroom projector',
                'category' => 'Audio visual',
                'serial_number' => 'DEMO-SERIAL-001',
                'acquired_on' => '2025-08-15',
                'acquisition_cost' => 24000,
                'location' => 'Demo Class 5',
                'condition' => 'good',
                'status' => 'active',
                'warranty_ends_on' => '2027-08-15',
                'custodian_employee_id' => $custodian->id,
                'notes' => 'Synthetic asset record.',
                'created_by' => $admin->id,
            ],
        );
        AssetLifecycleEvent::query()->firstOrCreate(
            ['school_asset_id' => $asset->id, 'event_type' => 'acquired'],
            [
                'occurred_on' => $asset->acquired_on,
                'to_status' => 'active',
                'to_condition' => 'good',
                'to_custodian_employee_id' => $custodian->id,
                'location' => $asset->location,
                'notes' => 'Demo asset registered.',
                'recorded_by' => $admin->id,
            ],
        );
    }

    private function seedNotification(int $sentBy): void
    {
        NotificationDelivery::query()->firstOrCreate(
            ['recipient' => 'parent.demo@example.test', 'subject' => 'Demo notification delivery'],
            [
                'channel' => 'email',
                'message' => 'Synthetic notification record. No message was sent.',
                'status' => 'sent',
                'provider_reference' => 'DEMO-NOTIFICATION-001',
                'sent_by' => $sentBy,
                'sent_at' => now()->subHour(),
            ],
        );
    }

    private function seedAudit(User $admin, School $school): void
    {
        AuditLog::query()->firstOrCreate(
            [
                'user_id' => $admin->id,
                'action' => 'demo.data_seeded',
                'subject_type' => School::class,
                'subject_id' => $school->id,
            ],
            ['changes' => ['synthetic' => true, 'school_slug' => $school->slug]],
        );
    }

    private function demoUser(string $email, string $name, string $roleName, School $school): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::DEMO_PASSWORD),
                'is_active' => true,
                'school_id' => $school->id,
            ],
        );
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', $roleName)->value('id'),
        ]);

        return $user;
    }
}
