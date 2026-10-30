"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Employee = { id: number; employee_id: string | null; first_name: string; last_name: string | null; department: string | null };
type LeaveRequest = {
  id: number; leave_type: string; starts_on: string; ends_on: string; reason: string | null;
  status: "pending" | "approved" | "rejected"; review_note: string | null; employee: Employee;
};
type LeaveData = { requests: LeaveRequest[]; employees: Employee[]; summary: { pending: number; approved: number; rejected: number } };

const employeeName = (employee: Employee) => `${employee.first_name} ${employee.last_name ?? ""}`.trim();

export function HrmsLeavePage() {
  const { user } = useSession();
  const [data, setData] = useState<LeaveData | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      setData(await apiRequest<LeaveData>("/leave"));
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const body = Object.fromEntries([...new FormData(form)].filter(([, value]) => value !== ""));
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/leave", { method: "POST", body });
      form.reset();
      setNotice("Leave request submitted for review.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function review(requestId: number, status: "approved" | "rejected") {
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest(`/leave/${requestId}/review`, { method: "POST", body: { status } });
      setNotice(`Leave request ${status}.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  const canCreate = hasPermission(user, "leave.create");
  const canApprove = hasPermission(user, "leave.approve");

  return <div className="page-stack">
    <header className="page-title-row"><div><div className="eyebrow">PEOPLE OPERATIONS</div><h1>Leave management<span className="heading-period">.</span></h1><p>Record leave requests, review decisions, and retain an auditable history. Leave is not automatically deducted from salary.</p></div><button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button></header>
    {error && <div className="notice notice-error" role="alert">{error}</div>}
    {notice && <div className="notice notice-success" role="status">{notice}</div>}
    {loading && <div className="loading-indicator">Loading leave requests…</div>}
    {data && <>
      <section className="dashboard-stat-grid">
        <article className="surface-card"><div className="eyebrow">PENDING</div><strong>{data.summary.pending}</strong><span>Awaiting decision</span></article>
        <article className="surface-card"><div className="eyebrow">APPROVED</div><strong>{data.summary.approved}</strong><span>Approved requests</span></article>
        <article className="surface-card"><div className="eyebrow">REJECTED</div><strong>{data.summary.rejected}</strong><span>Declined requests</span></article>
      </section>
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW REQUEST</div><h2>Record employee leave</h2></div></div>
        <form className="compact-form" onSubmit={(event) => void submit(event)}>
          <div className="form-two-col"><label className="field-label">Employee<select name="employee_id" required defaultValue=""><option value="" disabled>Select employee</option>{data.employees.map((employee) => <option key={employee.id} value={employee.id}>{employeeName(employee)}{employee.employee_id ? ` · ${employee.employee_id}` : ""}</option>)}</select></label><label className="field-label">Leave type<select name="leave_type" required defaultValue="annual"><option value="annual">Annual</option><option value="sick">Sick</option><option value="personal">Personal</option><option value="unpaid">Unpaid</option><option value="other">Other</option></select></label></div>
          <div className="form-two-col"><label className="field-label">From<input name="starts_on" type="date" required /></label><label className="field-label">Through<input name="ends_on" type="date" required /></label></div>
          <label className="field-label">Reason<textarea name="reason" maxLength={2000} rows={3} /></label>
          <button className="button button-primary" disabled={saving || !data.employees.length}>{saving ? "Submitting…" : "Submit leave request"}</button>
        </form>
      </section>}
      <section className="surface-card">
        <div className="card-heading"><div><div className="eyebrow">LEAVE REGISTER</div><h2>Requests and decisions</h2></div></div>
        <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Employee</th><th>Type</th><th>Period</th><th>Reason</th><th>Status</th><th>Review note</th><th>Action</th></tr></thead><tbody>
          {data.requests.map((request) => <tr key={request.id}><td>{employeeName(request.employee)}</td><td>{request.leave_type}</td><td>{formatDate(request.starts_on)} – {formatDate(request.ends_on)}</td><td>{request.reason ?? "—"}</td><td><span className="status-badge">{request.status}</span></td><td>{request.review_note ?? "—"}</td><td>{request.status === "pending" && canApprove && <div className="button-row"><button className="button button-secondary" disabled={saving} onClick={() => void review(request.id, "approved")}>Approve</button><button className="button button-secondary" disabled={saving} onClick={() => void review(request.id, "rejected")}>Reject</button></div>}</td></tr>)}
          {!data.requests.length && <tr><td colSpan={7}>No leave requests recorded.</td></tr>}
        </tbody></table></div>
      </section>
    </>}
  </div>;
}
