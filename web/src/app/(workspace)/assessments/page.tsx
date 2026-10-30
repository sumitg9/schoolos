"use client";

import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Session = { id: number; name: string };
type Section = { id: number; name: string };
type SchoolClass = { id: number; name: string; academic_session_id: number; sections: Section[] };
type Subject = { id: number; name: string; code: string | null; academic_session_id: number; classes: { id: number; name: string }[] };
type ExamSubject = { id: number; subject_id: number; exam_date: string; starts_at: string; maximum_marks: string; passing_marks: string; subject: Subject };
type Exam = { id: number; title: string; starts_on: string; ends_on: string; status: string; results_status: string; grading_scale: { label: string; min_percentage: number }[]; academic_session: Session; school_class: { id: number; name: string }; subjects: ExamSubject[] };
type Enrollment = { id: number; roll_number: string | null; section: Section | null; student: { admission_number: string; first_name: string; last_name: string | null } };
type Mark = { exam_schedule_subject_id: number; student_enrollment_id: number; marks_obtained: string; remarks: string | null };
type ReportSubject = { subject: Subject; maximum_marks: string; passing_marks: string; marks_obtained: string | null; remarks: string | null };
type ReportCard = { enrollment_id: number; student: Enrollment["student"]; section: Section | null; roll_number: string | null; subjects: ReportSubject[]; total_obtained: number | null; total_maximum: number; percentage: number | null; grade: string | null; result: string };
type ExamForm = { academic_session_id: string; school_class_id: string; title: string; exam_date: string; starts_at: string; duration_minutes: string; maximum_marks: string; passing_marks: string };
type GradeBand = { label: string; min_percentage: number };
type SubjectSchedule = { exam_date: string; starts_at: string; duration_minutes: string; maximum_marks: string; passing_marks: string };

const initialForm: ExamForm = {
  academic_session_id: "", school_class_id: "", title: "", exam_date: "",
  starts_at: "09:00", duration_minutes: "60", maximum_marks: "100", passing_marks: "35",
};
const initialGradingScale: GradeBand[] = [
  { label: "A+", min_percentage: 90 },
  { label: "A", min_percentage: 80 },
  { label: "B", min_percentage: 70 },
  { label: "C", min_percentage: 60 },
  { label: "D", min_percentage: 50 },
  { label: "F", min_percentage: 0 },
];

export default function AssessmentsPage() {
  const { user } = useSession();
  const [sessions, setSessions] = useState<Session[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [exams, setExams] = useState<Exam[]>([]);
  const [form, setForm] = useState(initialForm);
  const [subjectSchedules, setSubjectSchedules] = useState<Record<number, SubjectSchedule>>({});
  const [gradingScale, setGradingScale] = useState(initialGradingScale);
  const [selectedSubjects, setSelectedSubjects] = useState<number[]>([]);
  const [selectedExamId, setSelectedExamId] = useState("");
  const [selectedSubjectId, setSelectedSubjectId] = useState("");
  const [enrollments, setEnrollments] = useState<Enrollment[]>([]);
  const [marks, setMarks] = useState<Mark[]>([]);
  const [markValues, setMarkValues] = useState<Record<number, string>>({});
  const [markRemarks, setMarkRemarks] = useState<Record<number, string>>({});
  const [reportExamId, setReportExamId] = useState("");
  const [reportCards, setReportCards] = useState<ReportCard[]>([]);
  const [selectedCardId, setSelectedCardId] = useState("");
  const [reportCardsLoading, setReportCardsLoading] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreateExam = hasPermission(user, "exams.create");
  const canPublishExam = hasPermission(user, "exams.approve");
  const canApproveResults = hasPermission(user, "marks.approve");
  const canPublishResults = hasPermission(user, "marks.publish");
  const canEditMarks = hasPermission(user, "marks.edit");
  const canPrintCards = hasPermission(user, "marks.print");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [sessionResult, classResult, subjectResult, examResult] = await Promise.all([
        apiRequest<{ data: Session[] }>("/academic-sessions"),
        apiRequest<{ data: SchoolClass[] }>("/classes"),
        apiRequest<{ data: Subject[] }>("/subjects"),
        apiRequest<{ data: { data: Exam[] } }>("/exams?per_page=100"),
      ]);
      setSessions(sessionResult.data);
      setClasses(classResult.data);
      setSubjects(subjectResult.data);
      setExams(examResult.data.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  const availableClasses = classes.filter((item) => item.academic_session_id === Number(form.academic_session_id));
  const availableSubjects = subjects.filter((item) => item.academic_session_id === Number(form.academic_session_id)
    && item.classes.some((schoolClass) => schoolClass.id === Number(form.school_class_id)));
  const selectedExam = exams.find((exam) => exam.id === Number(selectedExamId));
  const selectedExamSubject = selectedExam?.subjects.find((subject) => subject.id === Number(selectedSubjectId));
  const reportExam = exams.find((exam) => exam.id === Number(reportExamId));
  const selectedCard = reportCards.find((card) => card.enrollment_id === Number(selectedCardId));

  async function createExam(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (selectedSubjects.length === 0) {
      setError("Select at least one subject for this schedule.");
      return;
    }
    const schedules = selectedSubjects.map((subjectId) => subjectSchedules[subjectId]);
    if (schedules.some((schedule) => !schedule?.exam_date || !schedule.starts_at || !schedule.duration_minutes || !schedule.maximum_marks || !schedule.passing_marks)) {
      setError("Complete the date, time, and marks settings for every selected subject.");
      return;
    }
    const dates = schedules.map((schedule) => schedule.exam_date).sort();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/exams", {
        method: "POST",
        body: {
          academic_session_id: Number(form.academic_session_id),
          school_class_id: Number(form.school_class_id),
          title: form.title,
          starts_on: dates[0],
          ends_on: dates[dates.length - 1],
          subjects: selectedSubjects.map((subjectId) => ({
            subject_id: subjectId,
            exam_date: subjectSchedules[subjectId].exam_date,
            starts_at: subjectSchedules[subjectId].starts_at,
            duration_minutes: Number(subjectSchedules[subjectId].duration_minutes),
            maximum_marks: Number(subjectSchedules[subjectId].maximum_marks),
            passing_marks: Number(subjectSchedules[subjectId].passing_marks),
          })),
          grading_scale: gradingScale,
        },
      });
      setNotice("Examination schedule created as a draft.");
      setForm(initialForm);
      setSelectedSubjects([]);
      setSubjectSchedules({});
      setGradingScale(initialGradingScale);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function publishExam(exam: Exam) {
    setError("");
    try {
      await apiRequest(`/exams/${exam.id}/publish`, { method: "POST" });
      setNotice(`${exam.title} schedule is published.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function approveResults(exam: Exam) {
    setError("");
    setNotice("");
    try {
      await apiRequest(`/exams/${exam.id}/approve-results`, { method: "POST" });
      setNotice(`${exam.title} results approved and locked for publication.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function publishResults(exam: Exam) {
    setError("");
    setNotice("");
    try {
      await apiRequest(`/exams/${exam.id}/publish-results`, { method: "POST" });
      setNotice(`${exam.title} results are now published to the student and parent portal.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function loadExamMarks(examId: string) {
    setSelectedExamId(examId);
    setSelectedSubjectId("");
    setMarks([]);
    setEnrollments([]);
    if (!examId) return;
    try {
      const data = await apiRequest<{ exam: Exam; enrollments: Enrollment[]; marks: Mark[] }>(`/exams/${examId}/marks`);
      setEnrollments(data.enrollments);
      setMarks(data.marks);
      setSelectedSubjectId(String(data.exam.subjects[0]?.id ?? ""));
      setMarkValues({});
      setMarkRemarks({});
      if (data.exam.results_status === "published" && canPrintCards) {
        await loadReportCards(examId);
      }
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function saveMarks(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedExam || !selectedExamSubject) return;
    const entries = enrollments
      .filter((enrollment) => markValues[enrollment.id] !== undefined && markValues[enrollment.id] !== "")
      .map((enrollment) => ({
        exam_schedule_subject_id: selectedExamSubject.id,
        student_enrollment_id: enrollment.id,
        marks_obtained: Number(markValues[enrollment.id]),
        remarks: markRemarks[enrollment.id] || null,
      }));
    if (!entries.length) {
      setError("Enter at least one mark before saving.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      await apiRequest(`/exams/${selectedExam.id}/marks`, { method: "PUT", body: { marks: entries } });
      setNotice(`Saved ${entries.length} mark record(s).`);
      await loadExamMarks(String(selectedExam.id));
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function loadReportCards(examId: string) {
    setReportExamId(examId);
    setSelectedCardId("");
    setReportCards([]);
    if (!examId) return;
    setReportCardsLoading(true);
    setError("");
    try {
      const result = await apiRequest<{ cards: ReportCard[] }>(`/exams/${examId}/report-cards`);
      setReportCards(result.cards);
      setSelectedCardId(String(result.cards[0]?.enrollment_id ?? ""));
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setReportCardsLoading(false);
    }
  }

  const markForSelectedSubject = useMemo(
    () => new Map(marks.filter((mark) => mark.exam_schedule_subject_id === Number(selectedSubjectId)).map((mark) => [mark.student_enrollment_id, mark])),
    [marks, selectedSubjectId],
  );

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">ACADEMIC MANAGEMENT</div><h1>Exams & report cards<span className="heading-period">.</span></h1><p>Schedule examinations, enter marks and prepare printable student report cards.</p></div>
        <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreateExam && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">EXAMINATION SCHEDULE</div><h2>Create a schedule</h2></div></div>
        <form className="compact-form" onSubmit={createExam}>
          <div className="form-two-col">
            <label className="field-label">Exam name<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} placeholder="Term 1 examination" required /></label>
            <label className="field-label">Academic session<select value={form.academic_session_id} onChange={(event) => { setForm({ ...form, academic_session_id: event.target.value, school_class_id: "" }); setSelectedSubjects([]); }} required><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label>
            <label className="field-label">Class<select value={form.school_class_id} onChange={(event) => { setForm({ ...form, school_class_id: event.target.value }); setSelectedSubjects([]); }} required><option value="">Select class</option>{availableClasses.map((schoolClass) => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name}</option>)}</select></label>
            <label className="field-label">Default subject date<input type="date" value={form.exam_date} onChange={(event) => setForm({ ...form, exam_date: event.target.value })} required /></label>
            <label className="field-label">Default start time<input type="time" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} required /></label>
            <label className="field-label">Default duration (minutes)<input type="number" min="15" max="360" value={form.duration_minutes} onChange={(event) => setForm({ ...form, duration_minutes: event.target.value })} required /></label>
            <label className="field-label">Default maximum marks<input type="number" min="1" step="0.5" value={form.maximum_marks} onChange={(event) => setForm({ ...form, maximum_marks: event.target.value })} required /></label>
            <label className="field-label">Default passing marks<input type="number" min="0" step="0.5" value={form.passing_marks} onChange={(event) => setForm({ ...form, passing_marks: event.target.value })} required /></label>
          </div>
          <fieldset className="role-picker"><legend>Grade bands (minimum percentage)</legend>
            <div className="form-two-col">{gradingScale.map((grade, index) => <label className="field-label" key={grade.label}>{grade.label}
              <input aria-label={`${grade.label} minimum percentage`} type="number" min="0" max="100" step="0.5" value={grade.min_percentage} disabled={index === gradingScale.length - 1} onChange={(event) => setGradingScale((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, min_percentage: Number(event.target.value) } : item))} />
            </label>)}</div>
          </fieldset>
          <fieldset className="role-picker"><legend>Subjects for this schedule</legend>{availableSubjects.length === 0 ? <span>Select an academic session to list subjects.</span> : availableSubjects.map((subject) => <label className="checkbox-line" key={subject.id}><input type="checkbox" checked={selectedSubjects.includes(subject.id)} onChange={(event) => {
            if (event.target.checked) {
              setSelectedSubjects((current) => [...current, subject.id]);
              setSubjectSchedules((current) => ({
                ...current,
                [subject.id]: {
                  exam_date: form.exam_date,
                  starts_at: form.starts_at,
                  duration_minutes: form.duration_minutes,
                  maximum_marks: form.maximum_marks,
                  passing_marks: form.passing_marks,
                },
              }));
            } else {
              setSelectedSubjects((current) => current.filter((id) => id !== subject.id));
              setSubjectSchedules((current) => {
                const next = { ...current };
                delete next[subject.id];
                return next;
              });
            }
          }} />{subject.name}{subject.code ? ` (${subject.code})` : ""}</label>)}</fieldset>
          {!!selectedSubjects.length && <section className="surface-card"><h3>Subject dates and marks</h3><div className="table-scroll"><table className="data-table"><thead><tr><th>Subject</th><th>Date</th><th>Start</th><th>Minutes</th><th>Maximum</th><th>Pass mark</th></tr></thead><tbody>
            {selectedSubjects.map((subjectId) => {
              const subject = availableSubjects.find((item) => item.id === subjectId);
              const schedule = subjectSchedules[subjectId];
              if (!subject || !schedule) return null;
              const updateSchedule = (changes: Partial<SubjectSchedule>) => setSubjectSchedules((current) => ({
                ...current,
                [subjectId]: { ...current[subjectId], ...changes },
              }));
              return <tr key={subjectId}><td>{subject.name}</td>
                <td><input aria-label={`${subject.name} exam date`} type="date" required value={schedule.exam_date} onChange={(event) => updateSchedule({ exam_date: event.target.value })} /></td>
                <td><input aria-label={`${subject.name} start time`} type="time" required value={schedule.starts_at} onChange={(event) => updateSchedule({ starts_at: event.target.value })} /></td>
                <td><input aria-label={`${subject.name} duration`} type="number" min="15" max="360" required value={schedule.duration_minutes} onChange={(event) => updateSchedule({ duration_minutes: event.target.value })} /></td>
                <td><input aria-label={`${subject.name} maximum marks`} type="number" min="1" step="0.5" required value={schedule.maximum_marks} onChange={(event) => updateSchedule({ maximum_marks: event.target.value })} /></td>
                <td><input aria-label={`${subject.name} passing marks`} type="number" min="0" step="0.5" required value={schedule.passing_marks} onChange={(event) => updateSchedule({ passing_marks: event.target.value })} /></td>
              </tr>;
            })}
          </tbody></table></div></section>}
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create draft schedule"}</button>
        </form>
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">EXAM REGISTER</div><h2>Schedules</h2></div><span className="count-pill">{exams.length}</span></div>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>EXAM</th><th>CLASS / SESSION</th><th>DATES</th><th>SUBJECTS</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {loading ? <tr><td className="table-empty" colSpan={6}>Loading schedules…</td></tr> : exams.length === 0 ? <tr><td className="table-empty" colSpan={6}>No examination schedules yet.</td></tr> : exams.map((exam) => <tr key={exam.id}>
            <td><strong>{exam.title}</strong></td><td>{exam.school_class.name}<small>{exam.academic_session.name}</small></td>
            <td>{formatDate(exam.starts_on)}<small>through {formatDate(exam.ends_on)}</small></td>
            <td>{exam.subjects.map((item) => item.subject.name).join(", ")}</td><td>{exam.status}<small>Results: {exam.results_status}</small></td>
            <td>
              {exam.status === "draft" && canPublishExam && <button className="text-link" onClick={() => void publishExam(exam)}>Publish schedule</button>}
              {exam.status === "published" && exam.results_status === "draft" && canApproveResults && <button className="text-link" onClick={() => void approveResults(exam)}>Approve results</button>}
              {exam.results_status === "approved" && canPublishResults && <button className="text-link" onClick={() => void publishResults(exam)}>Publish results</button>}
            </td>
          </tr>)}
        </tbody></table></div>
      </section>
      {canEditMarks && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">ASSESSMENT ENTRY</div><h2>Enter marks</h2></div></div>
        <div className="form-two-col">
          <label className="field-label">Published examination<select value={selectedExamId} onChange={(event) => void loadExamMarks(event.target.value)}><option value="">Select exam</option>{exams.filter((exam) => exam.status === "published").map((exam) => <option key={exam.id} value={exam.id}>{exam.title} · {exam.school_class.name} · results {exam.results_status}</option>)}</select></label>
          <label className="field-label">Subject<select value={selectedSubjectId} onChange={(event) => { setSelectedSubjectId(event.target.value); setMarkValues({}); setMarkRemarks({}); }} disabled={!selectedExam}><option value="">Select subject</option>{selectedExam?.subjects.map((item) => <option key={item.id} value={item.id}>{item.subject.name} · max {item.maximum_marks}</option>)}</select></label>
        </div>
        {selectedExam && selectedExamSubject && <form className="compact-form" onSubmit={saveMarks}>
          {selectedExam.results_status !== "draft" && <div className="notice notice-success" role="status">
            Results are {selectedExam.results_status}; mark entry is locked.
            {selectedExam.results_status === "published" && canPrintCards && " The report card is loaded in the Report Cards section below."}
          </div>}
          <div className="table-scroll"><table className="data-table"><thead><tr><th>STUDENT</th><th>SECTION / ROLL</th><th>MARKS (MAX {selectedExamSubject.maximum_marks})</th><th>REMARKS</th></tr></thead><tbody>
            {enrollments.map((enrollment) => {
              const existing = markForSelectedSubject.get(enrollment.id);
              return <tr key={enrollment.id}>
                <td><strong>{enrollment.student.first_name} {enrollment.student.last_name}</strong><small>{enrollment.student.admission_number}</small></td>
                <td>{enrollment.section?.name ?? "—"}{enrollment.roll_number ? ` / ${enrollment.roll_number}` : ""}</td>
                <td><input aria-label={`Marks for ${enrollment.student.first_name}`} type="number" min="0" max={selectedExamSubject.maximum_marks} step="0.5" disabled={selectedExam.results_status !== "draft"} value={markValues[enrollment.id] ?? existing?.marks_obtained ?? ""} onChange={(event) => setMarkValues((current) => ({ ...current, [enrollment.id]: event.target.value }))} /></td>
                <td><input aria-label={`Remarks for ${enrollment.student.first_name}`} disabled={selectedExam.results_status !== "draft"} value={markRemarks[enrollment.id] ?? existing?.remarks ?? ""} onChange={(event) => setMarkRemarks((current) => ({ ...current, [enrollment.id]: event.target.value }))} /></td>
              </tr>;
            })}
          </tbody></table></div>
          {selectedExam.results_status === "draft" && <button className="button button-primary" disabled={saving || enrollments.length === 0}>{saving ? "Saving…" : "Save marks"}</button>}
        </form>}
      </section>}
      {canPrintCards && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">REPORT CARDS</div><h2>Generate printable report card</h2></div></div>
        <div className="form-two-col">
          <label className="field-label">Published examination<select value={reportExamId} onChange={(event) => void loadReportCards(event.target.value)}><option value="">Select exam</option>{exams.filter((exam) => exam.status === "published").map((exam) => <option key={exam.id} value={exam.id}>{exam.title} · {exam.school_class.name}</option>)}</select></label>
          <label className="field-label">Student<select value={selectedCardId} onChange={(event) => setSelectedCardId(event.target.value)} disabled={!reportExamId || reportCardsLoading}><option value="">{reportCardsLoading ? "Loading students…" : "Select student"}</option>{reportCards.map((card) => <option key={card.enrollment_id} value={card.enrollment_id}>{card.student.first_name} {card.student.last_name} · {card.roll_number ?? card.student.admission_number}</option>)}</select></label>
        </div>
        {reportExamId && !reportCardsLoading && reportCards.length === 0 && <p className="muted-copy">No students were found for this exam. Check that students are enrolled in its class and academic session, or that marks have been entered for them.</p>}
        {selectedCard && <div className="print-report-card">
          <h2>{reportExam?.school_class.name} · {selectedCard.student.first_name} {selectedCard.student.last_name}</h2>
          <p>{reportExam?.title} · {reportExam?.academic_session.name}</p>
          <p>Admission: {selectedCard.student.admission_number} · Roll: {selectedCard.roll_number ?? "—"} · Section: {selectedCard.section?.name ?? "—"}</p>
          <div className="table-scroll"><table className="data-table"><thead><tr><th>SUBJECT</th><th>MARKS</th><th>MAXIMUM</th><th>RESULT</th></tr></thead><tbody>
            {selectedCard.subjects.map((item) => <tr key={item.subject.id}><td>{item.subject.name}</td><td>{item.marks_obtained ?? "Pending"}</td><td>{item.maximum_marks}</td><td>{item.marks_obtained === null ? "Pending" : Number(item.marks_obtained) >= Number(item.passing_marks) ? "Pass" : "Below pass mark"}</td></tr>)}
          </tbody></table></div>
          <p><strong>Total:</strong> {selectedCard.total_obtained ?? "Pending"} / {selectedCard.total_maximum} · <strong>Percentage:</strong> {selectedCard.percentage === null ? "Pending marks" : `${selectedCard.percentage}%`} · <strong>Grade:</strong> {selectedCard.grade ?? "—"} · <strong>Result:</strong> {selectedCard.result}</p>
          <button className="button button-primary no-print" onClick={() => window.print()}>Print report card</button>
        </div>}
      </section>}
    </div>
  );
}
