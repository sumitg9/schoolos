"use client";

import { useEffect, useState } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDateTime } from "@/lib/date";

type Guardian = { id: number; first_name: string; last_name: string | null; phone: string; portal_user_id?: number | null };
type Student = { id: number; admission_number: string; first_name: string; last_name: string | null; status: string; portal_user_id?: number | null; guardians: Guardian[]; enrollments: { status: string }[] };
type Activation = { code: string; target_type: string; expires_at: string };

export default function PortalAccountsPage() {
  const [students, setStudents] = useState<Student[]>([]);
  const [studentId, setStudentId] = useState("");
  const [targetType, setTargetType] = useState<"student" | "guardian">("student");
  const [guardianId, setGuardianId] = useState("");
  const [activation, setActivation] = useState<Activation | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    apiRequest<{ data: Student[] }>("/students?per_page=100")
      .then(({ data }) => {
        setStudents(data.filter((student) => student.status === "active"));
        setStudentId(String(data.find((student) => student.status === "active")?.id ?? ""));
      })
      .catch((reason: unknown) => setError(errorMessage(reason)))
      .finally(() => setLoading(false));
  }, []);

  const selectedStudent = students.find((student) => String(student.id) === studentId);
  async function createCode() {
    const targetId = targetType === "student" ? studentId : guardianId;
    if (!targetId) {
      setError(targetType === "student" ? "Select an active student." : "Select a guardian.");
      return;
    }
    setSaving(true);
    setError("");
    setNotice("");
    setActivation(null);
    try {
      const result = await apiRequest<{ data: Activation }>("/portal/activation-codes", {
        method: "POST",
        body: { target_type: targetType, target_id: Number(targetId) },
      });
      setActivation(result.data);
      setNotice("Code created. Copy and share it with the intended user; it will not be shown again.");
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function copyCode() {
    if (!activation) return;
    try {
      await navigator.clipboard.writeText(activation.code);
      setNotice("Activation code copied. Share it securely with the intended user.");
    } catch {
      setError("Could not copy the activation code. Select and copy the displayed code manually.");
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row"><div><div className="eyebrow">PORTAL ACCESS</div><h1>Activation codes<span className="heading-period">.</span></h1><p>Create one-time school-issued codes for student or parent self-registration.</p></div></header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">ISSUE CODE</div><h2>Choose a school record</h2></div></div>
        <div className="compact-form">
          <label className="field-label">Account type<select value={targetType} onChange={(event) => { setTargetType(event.target.value as "student" | "guardian"); setActivation(null); }}><option value="student">Student</option><option value="guardian">Parent / guardian</option></select></label>
          <label className="field-label">Student<select value={studentId} onChange={(event) => { setStudentId(event.target.value); setGuardianId(""); setActivation(null); }} disabled={loading}><option value="">Select student</option>{students.map((student) => <option key={student.id} value={student.id}>{student.first_name} {student.last_name ?? ""} · {student.admission_number}</option>)}</select></label>
          {targetType === "guardian" && <label className="field-label">Guardian<select value={guardianId} onChange={(event) => { setGuardianId(event.target.value); setActivation(null); }}><option value="">Select guardian</option>{(selectedStudent?.guardians ?? []).filter((guardian) => !guardian.portal_user_id).map((guardian) => <option key={guardian.id} value={guardian.id}>{guardian.first_name} {guardian.last_name ?? ""} · {guardian.phone}</option>)}</select></label>}
          <p className="muted-copy">Codes expire after 30 days and can be redeemed once. Parent activation links the guardian’s associated active students to the parent portal.</p>
          <button className="button button-primary" disabled={saving || loading || (targetType === "student" && !studentId) || (targetType === "guardian" && !guardianId)} onClick={() => void createCode()}>{saving ? "Creating…" : "Create one-time code"}</button>
        </div>
      </section>
      {activation && <section className="surface-card">
        <div className="card-heading"><div><div className="eyebrow">SHOW ONCE</div><h2>Activation code</h2></div></div>
        <label className="field-label">Share this code securely<input readOnly value={activation.code} onFocus={(event) => event.currentTarget.select()} /></label>
        <p className="muted-copy">Expires {formatDateTime(activation.expires_at)}. The code is stored as a hash and cannot be retrieved after leaving this page.</p>
        <button className="button button-secondary" onClick={() => void copyCode()}>Copy code</button>
      </section>}
    </div>
  );
}
