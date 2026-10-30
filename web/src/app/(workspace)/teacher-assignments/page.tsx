"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type AcademicSession = { id: number; name: string };
type Section = { id: number; name: string };
type SchoolClass = { id: number; name: string; academic_session_id: number; sections: Section[] };
type Subject = { id: number; name: string; code: string | null; academic_session_id: number; classes: { id: number; name: string }[] };
type Employee = { id: number; employee_id: string; first_name: string; last_name: string | null; is_teaching_staff: boolean; status: string };
type Assignment = {
  id: number;
  is_class_teacher: boolean;
  employee: { employee_id: string; first_name: string; last_name: string | null };
  academic_session: { name: string };
  school_class: { name: string };
  section: { name: string } | null;
  subject: { name: string; code: string | null } | null;
};

const initialForm = { employee_id: "", academic_session_id: "", school_class_id: "", section_id: "", subject_id: "", is_class_teacher: false };

export default function TeacherAssignmentsPage() {
  const { user } = useSession();
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [sessions, setSessions] = useState<AcademicSession[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [form, setForm] = useState(initialForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreate = hasPermission(user, "teacher_assignments.create");
  const canDelete = hasPermission(user, "teacher_assignments.delete");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [employeeResult, sessionResult, classResult, subjectResult, assignmentResult] = await Promise.all([
        apiRequest<{ data: Employee[] }>("/employees?per_page=100"),
        apiRequest<{ data: AcademicSession[] }>("/academic-sessions"),
        apiRequest<{ data: SchoolClass[] }>("/classes"),
        apiRequest<{ data: Subject[] }>("/subjects"),
        apiRequest<{ data: { data: Assignment[] } }>("/teacher-assignments?per_page=100"),
      ]);
      setEmployees(employeeResult.data.filter((employee) => employee.is_teaching_staff && employee.status === "active"));
      setSessions(sessionResult.data);
      setClasses(classResult.data);
      setSubjects(subjectResult.data);
      setAssignments(assignmentResult.data.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function createAssignment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/teacher-assignments", {
        method: "POST",
        body: {
          employee_id: Number(form.employee_id),
          academic_session_id: Number(form.academic_session_id),
          school_class_id: Number(form.school_class_id),
          section_id: form.section_id ? Number(form.section_id) : null,
          subject_id: form.subject_id ? Number(form.subject_id) : null,
          is_class_teacher: form.is_class_teacher,
        },
      });
      setNotice("Teacher assignment created.");
      setForm(initialForm);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function removeAssignment(assignment: Assignment) {
    if (!window.confirm("End this assignment? Its history will be preserved.")) return;
    try {
      await apiRequest(`/teacher-assignments/${assignment.id}`, { method: "DELETE" });
      setNotice("Assignment ended.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  const selectedClass = classes.find((item) => item.id === Number(form.school_class_id));
  const availableClasses = classes.filter((item) => item.academic_session_id === Number(form.academic_session_id));
  const availableSubjects = subjects.filter((item) => item.academic_session_id === Number(form.academic_session_id)
    && item.classes.some((schoolClass) => schoolClass.id === Number(form.school_class_id)));

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">STAFF AND ACADEMICS</div><h1>Teacher assignments<span className="heading-period">.</span></h1><p>Assign teaching staff to classes, sections and subjects for each academic session.</p></div>
        <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW ASSIGNMENT</div><h2>Assign a teacher</h2></div></div>
        <form className="compact-form" onSubmit={createAssignment}>
          <div className="form-two-col">
            <label className="field-label">Teaching employee<select value={form.employee_id} onChange={(event) => setForm({ ...form, employee_id: event.target.value })} required><option value="">Select employee</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.first_name} {employee.last_name} · {employee.employee_id}</option>)}</select></label>
            <label className="field-label">Academic session<select value={form.academic_session_id} onChange={(event) => setForm({ ...form, academic_session_id: event.target.value, school_class_id: "", section_id: "", subject_id: "" })} required><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label>
            <label className="field-label">Class<select value={form.school_class_id} onChange={(event) => setForm({ ...form, school_class_id: event.target.value, section_id: "", subject_id: "" })} required><option value="">Select class</option>{availableClasses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field-label">Section<select value={form.section_id} onChange={(event) => setForm({ ...form, section_id: event.target.value })}><option value="">Whole class</option>{selectedClass?.sections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
            <label className="field-label">Subject<select value={form.subject_id} onChange={(event) => setForm({ ...form, subject_id: event.target.value })}><option value="">No specific subject</option>{availableSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}{subject.code ? ` (${subject.code})` : ""}</option>)}</select></label>
          </div>
          <label className="checkbox-line"><input type="checkbox" checked={form.is_class_teacher} onChange={(event) => setForm({ ...form, is_class_teacher: event.target.checked })} /> Class teacher</label>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create assignment"}</button>
        </form>
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">CURRENT ASSIGNMENTS</div><h2>Teaching schedule</h2></div><span className="count-pill">{assignments.length}</span></div>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>TEACHER</th><th>SESSION / CLASS</th><th>SECTION</th><th>SUBJECT</th><th>ROLE</th><th>ACTION</th></tr></thead><tbody>
          {loading ? <tr><td className="table-empty" colSpan={6}>Loading assignments…</td></tr> : assignments.length === 0 ? <tr><td className="table-empty" colSpan={6}>No active assignments found.</td></tr> : assignments.map((assignment) => <tr key={assignment.id}>
            <td><strong>{assignment.employee.first_name} {assignment.employee.last_name}</strong><small>{assignment.employee.employee_id}</small></td>
            <td>{assignment.school_class.name}<small>{assignment.academic_session.name}</small></td><td>{assignment.section?.name ?? "Whole class"}</td>
            <td>{assignment.subject?.name ?? "—"}{assignment.subject?.code && <small>{assignment.subject.code}</small>}</td><td>{assignment.is_class_teacher ? "Class teacher" : "Subject teacher"}</td>
            <td>{canDelete && <button className="text-link" onClick={() => void removeAssignment(assignment)}>End assignment</button>}</td>
          </tr>)}
        </tbody></table></div>
      </section>
    </div>
  );
}
