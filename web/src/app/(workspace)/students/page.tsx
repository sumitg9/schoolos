"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, downloadFile, errorMessage } from "@/lib/api";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type AcademicSession = { id: number; name: string; is_active: boolean };
type Section = { id: number; name: string };
type SchoolClass = { id: number; name: string; academic_session_id: number; sections: Section[] };
type Enrollment = {
  academic_session?: { name: string };
  school_class?: { name: string };
  section?: { name: string } | null;
  roll_number?: string | null;
};
type Student = {
  id: number;
  admission_number: string;
  first_name: string;
  last_name: string | null;
  status: string;
  guardians: { first_name: string; last_name: string | null; phone: string }[];
  enrollments: Enrollment[];
};
type ImportError = { row: number; message: string };

const initialForm = {
  first_name: "", last_name: "", date_of_birth: "", admission_date: new Date().toISOString().slice(0, 10),
  gender: "", academic_session_id: "", school_class_id: "", section_id: "", roll_number: "",
  guardian_first_name: "", guardian_last_name: "", guardian_phone: "", guardian_email: "", guardian_relationship: "Parent",
};

export default function StudentsPage() {
  const { user } = useSession();
  const [students, setStudents] = useState<Student[]>([]);
  const [sessions, setSessions] = useState<AcademicSession[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [form, setForm] = useState(initialForm);
  const [search, setSearch] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [importErrors, setImportErrors] = useState<ImportError[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreate = hasPermission(user, "students.create");
  const canImport = hasPermission(user, "students.import");
  const canExport = hasPermission(user, "students.export");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [studentResult, sessionResult, classResult] = await Promise.all([
        apiRequest<{ data: Student[] }>(`/students?per_page=100&search=${encodeURIComponent(search)}`),
        apiRequest<{ data: AcademicSession[] }>("/academic-sessions"),
        apiRequest<{ data: SchoolClass[] }>("/classes"),
      ]);
      setStudents(studentResult.data);
      setSessions(sessionResult.data);
      setClasses(classResult.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function submitAdmission(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const schoolClass = classes.find((item) => item.id === Number(form.school_class_id));
      const sectionId = form.section_id || null;
      if (!schoolClass) throw new Error("Choose a class for this admission.");
      await apiRequest("/students", {
        method: "POST",
        body: {
          first_name: form.first_name,
          last_name: form.last_name || null,
          date_of_birth: form.date_of_birth,
          admission_date: form.admission_date,
          gender: form.gender || null,
          academic_session_id: Number(form.academic_session_id),
          school_class_id: schoolClass.id,
          section_id: sectionId ? Number(sectionId) : null,
          roll_number: form.roll_number || null,
          guardians: [{
            first_name: form.guardian_first_name,
            last_name: form.guardian_last_name || null,
            phone: form.guardian_phone,
            email: form.guardian_email || null,
            relationship: form.guardian_relationship,
            is_primary: true,
          }],
        },
      });
      setNotice("Student admitted and enrollment history created.");
      setForm(initialForm);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function withdraw(student: Student) {
    if (!window.confirm(`Withdraw ${student.first_name} ${student.last_name ?? ""}? This preserves their records.`)) return;
    setError("");
    try {
      await apiRequest(`/students/${student.id}`, { method: "DELETE", body: {} });
      setNotice(`${student.admission_number} was withdrawn.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function importCsv(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!file || !form.academic_session_id || !form.school_class_id) {
      setError("Choose a CSV file, session and class before importing.");
      return;
    }
    const data = new FormData();
    data.set("file", file);
    data.set("academic_session_id", form.academic_session_id);
    data.set("school_class_id", form.school_class_id);
    if (form.section_id) data.set("section_id", form.section_id);
    setSaving(true);
    setError("");
    try {
      const result = await apiRequest<{ created_count: number; error_count: number; errors: ImportError[] }>("/students/import", { method: "POST", body: data });
      setNotice(`Imported ${result.created_count} student(s); ${result.error_count} row(s) need correction.`);
      setImportErrors(result.errors);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  const availableClasses = classes.filter((item) => item.academic_session_id === Number(form.academic_session_id));
  const selectedClass = availableClasses.find((item) => item.id === Number(form.school_class_id));

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">STUDENT INFORMATION</div><h1>Students<span className="heading-period">.</span></h1><p>Manage admissions, guardians and current enrollment without losing student history.</p></div>
        <div className="button-row">
          {canExport && <button className="button button-secondary" onClick={() => void downloadFile("/students/export", "students.csv").catch((reason) => setError(errorMessage(reason)))}>Export CSV</button>}
          <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
        </div>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW RECORD</div><h2>Admit a student</h2></div></div>
        <form className="compact-form" onSubmit={submitAdmission}>
          <div className="form-two-col">
            <label className="field-label">First name<input value={form.first_name} onChange={(event) => setForm({ ...form, first_name: event.target.value })} required /></label>
            <label className="field-label">Last name<input value={form.last_name} onChange={(event) => setForm({ ...form, last_name: event.target.value })} /></label>
            <label className="field-label">Date of birth<input type="date" value={form.date_of_birth} onChange={(event) => setForm({ ...form, date_of_birth: event.target.value })} required /></label>
            <label className="field-label">Admission date<input type="date" value={form.admission_date} onChange={(event) => setForm({ ...form, admission_date: event.target.value })} required /></label>
            <label className="field-label">Academic session<select value={form.academic_session_id} onChange={(event) => setForm({ ...form, academic_session_id: event.target.value, school_class_id: "", section_id: "" })} required><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}{session.is_active ? " (active)" : ""}</option>)}</select></label>
            <label className="field-label">Class<select value={form.school_class_id} onChange={(event) => setForm({ ...form, school_class_id: event.target.value, section_id: "" })} required><option value="">Select class</option>{availableClasses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field-label">Section<select value={form.section_id} onChange={(event) => setForm({ ...form, section_id: event.target.value })}><option value="">No section</option>{selectedClass?.sections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
            <label className="field-label">Roll number<input value={form.roll_number} onChange={(event) => setForm({ ...form, roll_number: event.target.value })} /></label>
          </div>
          <div className="eyebrow">PRIMARY GUARDIAN</div>
          <div className="form-two-col">
            <label className="field-label">First name<input value={form.guardian_first_name} onChange={(event) => setForm({ ...form, guardian_first_name: event.target.value })} required /></label>
            <label className="field-label">Last name<input value={form.guardian_last_name} onChange={(event) => setForm({ ...form, guardian_last_name: event.target.value })} /></label>
            <label className="field-label">Phone<input value={form.guardian_phone} onChange={(event) => setForm({ ...form, guardian_phone: event.target.value })} required /></label>
            <label className="field-label">Email<input type="email" value={form.guardian_email} onChange={(event) => setForm({ ...form, guardian_email: event.target.value })} /></label>
            <label className="field-label">Relationship<input value={form.guardian_relationship} onChange={(event) => setForm({ ...form, guardian_relationship: event.target.value })} required /></label>
          </div>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create admission"}</button>
        </form>
        {canImport && <form className="compact-form" onSubmit={importCsv}>
          <div className="eyebrow">BULK IMPORT</div>
          <div className="button-row">
            <button type="button" className="button button-secondary" onClick={() => void downloadFile("/students/import-template", "student-import-template.csv").catch((reason) => setError(errorMessage(reason)))}>Download template</button>
            <label className="field-label">CSV file<input type="file" accept=".csv,text/csv" onChange={(event) => setFile(event.target.files?.[0] ?? null)} required /></label>
            <button className="button button-secondary" disabled={saving || !file}>Import CSV</button>
          </div>
        </form>}
        {importErrors.length > 0 && <ul className="notice notice-error" aria-label="CSV import row errors">{importErrors.map((item, index) => <li key={`${item.row}-${index}`}>Row {item.row}: {item.message}</li>)}</ul>}
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">ENROLLMENT REGISTER</div><h2>Student records</h2></div><span className="count-pill">{students.length}</span></div>
        <form className="button-row" onSubmit={(event) => { event.preventDefault(); void load(); }}>
          <label className="field-label">Search students<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name or admission number" /></label>
          <button className="button button-secondary">Search</button>
        </form>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>STUDENT</th><th>ENROLLMENT</th><th>GUARDIAN</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {loading ? <tr><td className="table-empty" colSpan={5}>Loading student records…</td></tr> : students.length === 0 ? <tr><td className="table-empty" colSpan={5}>No students found.</td></tr> : students.map((student) => {
            const enrollment = student.enrollments[0];
            const guardian = student.guardians[0];
            return <tr key={student.id}>
              <td><strong>{student.first_name} {student.last_name}</strong><small>{student.admission_number}</small></td>
              <td>{enrollment?.school_class?.name ?? "—"}{enrollment?.section ? ` / ${enrollment.section.name}` : ""}<small>{enrollment?.academic_session?.name ?? "No active enrollment"}{enrollment?.roll_number ? ` · Roll ${enrollment.roll_number}` : ""}</small></td>
              <td>{guardian ? `${guardian.first_name} ${guardian.last_name ?? ""}` : "—"}<small>{guardian?.phone ?? ""}</small></td>
              <td>{student.status}</td>
              <td>{hasPermission(user, "students.edit") && student.status === "active" && <button className="text-link" onClick={() => void withdraw(student)}>Withdraw</button>}</td>
            </tr>;
          })}
        </tbody></table></div>
      </section>
    </div>
  );
}
