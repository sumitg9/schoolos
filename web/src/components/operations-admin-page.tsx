"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type ModuleName = "expenses" | "assets" | "events";
type Person = { id: number; employee_id: string | null; first_name: string; last_name: string | null; department?: string | null };
type EventOption = { id: number; name: string; budget: string; starts_on: string };
type Expense = {
  id: number; event_id: number | null; description: string; category: string; vendor: string | null; spent_on: string;
  amount: string; reference: string | null; status: "pending" | "approved" | "rejected" | "paid";
  review_note: string | null; payment_reference: string | null; event: EventOption | null; submitted_by: number;
};
type ExpenseData = {
  expenses: Expense[]; events: EventOption[];
  report: { count: number; pending: number; approved: number; paid: number; rejected: number; pending_amount: string; approved_amount: string; paid_amount: string };
};
type AssetLifecycle = {
  id: number; event_type: string; occurred_on: string; from_status: string | null; to_status: string | null;
  from_condition: string | null; to_condition: string | null; location: string | null; notes: string | null;
};
type Asset = {
  id: number; asset_tag: string; name: string; category: string; serial_number: string | null; acquired_on: string;
  acquisition_cost: string; location: string | null; condition: string; status: "active" | "maintenance" | "disposed" | "lost";
  warranty_ends_on: string | null; custodian_employee_id: number | null; custodian: Person | null;
  lifecycle_events: AssetLifecycle[];
};
type AssetData = {
  assets: Asset[]; employees: Person[];
  report: { count: number; active: number; maintenance: number; disposed: number; lost: number; acquisition_value: string };
};
type SchoolEvent = {
  id: number; name: string; category: string | null; description: string | null; venue: string | null;
  starts_on: string; ends_on: string; budget: string; status: "planned" | "in_progress" | "completed" | "cancelled";
  committed_expenses: string; pending_amount: string | null; rejected_amount: string | null;
  pending_expense_count: number; approved_expense_count: number; budget_overrun: boolean; expenses: Expense[];
};
type EventData = { events: SchoolEvent[] };
type ModuleData = ExpenseData | AssetData | EventData;

const employeeName = (person: Person) => `${person.first_name} ${person.last_name ?? ""}`.trim();
const money = (amount: string | number) => Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = new Date().toISOString().slice(0, 10);

export function OperationsAdminPage({ module }: { module: ModuleName }) {
  const { user } = useSession();
  const [data, setData] = useState<ModuleData | null>(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [paymentId, setPaymentId] = useState<number | null>(null);
  const [paymentReference, setPaymentReference] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      if (module === "expenses") setData(await apiRequest<ExpenseData>("/expenses"));
      if (module === "assets") setData(await apiRequest<AssetData>("/assets"));
      if (module === "events") setData(await apiRequest<EventData>("/events"));
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [module]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function submit(event: FormEvent<HTMLFormElement>, endpoint: string, method: "POST" | "PATCH" = "POST", jsonBody?: unknown, includeEmpty = false) {
    event.preventDefault();
    const form = event.currentTarget;
    const formEntries = [...new FormData(form)];
    const body = jsonBody ?? Object.fromEntries(includeEmpty ? formEntries : formEntries.filter(([, value]) => value !== ""));
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest(endpoint, { method, body });
      form.reset();
      setNotice("Changes saved.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function action(endpoint: string, message: string, body: unknown = {}) {
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest(endpoint, { method: "POST", body });
      setNotice(message);
      setPaymentId(null);
      setPaymentReference("");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  const titles: Record<ModuleName, { eyebrow: string; title: string; description: string }> = {
    expenses: { eyebrow: "FINANCE OPERATIONS", title: "Expenses", description: "Submit, approve and record payment of school expenses with an audit trail." },
    assets: { eyebrow: "SCHOOL OPERATIONS", title: "Asset register", description: "Track asset values, custodians, condition and lifecycle changes." },
    events: { eyebrow: "SCHOOL CALENDAR", title: "Events", description: "Plan events, set budgets and monitor linked expense approvals and spend." },
  };
  const title = titles[module];
  const expenses = module === "expenses" ? data as ExpenseData | null : null;
  const assets = module === "assets" ? data as AssetData | null : null;
  const events = module === "events" ? data as EventData | null : null;
  const canCreate = hasPermission(user, `${module}.create`);

  return <div className="page-stack">
    <header className="page-title-row"><div><div className="eyebrow">{title.eyebrow}</div><h1>{title.title}<span className="heading-period">.</span></h1><p>{title.description}</p></div><button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button></header>
    {error && <div className="notice notice-error" role="alert">{error}</div>}
    {notice && <div className="notice notice-success" role="status">{notice}</div>}
    {loading && <div className="loading-indicator">Loading {title.title.toLowerCase()}…</div>}

    {expenses && <>
      <section className="dashboard-stat-grid">
        <Stat label="PENDING" value={expenses.report.pending} caption={`${money(expenses.report.pending_amount)} awaiting review`} />
        <Stat label="APPROVED" value={expenses.report.approved} caption={`${money(expenses.report.approved_amount)} to disburse`} />
        <Stat label="PAID" value={expenses.report.paid} caption={`${money(expenses.report.paid_amount)} paid`} />
        <Stat label="REGISTER" value={expenses.report.count} caption={`${expenses.report.rejected} rejected`} />
      </section>
      {canCreate && <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">NEW CLAIM</div><h2>Submit an expense</h2></div></div>
        <form className="compact-form" onSubmit={(event) => void submit(event, "/expenses")}>
          <label className="field-label">Description<input name="description" maxLength={180} required /></label>
          <div className="form-two-col"><label className="field-label">Category<input name="category" maxLength={80} required placeholder="Supplies, utilities, travel…" /></label><label className="field-label">Vendor<input name="vendor" maxLength={180} /></label></div>
          <div className="form-two-col"><label className="field-label">Amount<input name="amount" type="number" min="0.01" step="0.01" required /></label><label className="field-label">Expense date<input name="spent_on" type="date" defaultValue={today} required /></label></div>
          <div className="form-two-col"><label className="field-label">Reference / receipt number<input name="reference" maxLength={100} /></label><label className="field-label">School event<select name="event_id" defaultValue=""><option value="">General school expense</option>{expenses.events.map((event) => <option key={event.id} value={event.id}>{event.name} · budget {money(event.budget)}</option>)}</select></label></div>
          <p className="muted-copy">Expenses are submitted for independent review. An approved expense may then be recorded as paid by a user other than its submitter.</p>
          <button className="button button-primary" disabled={saving}>{saving ? "Submitting…" : "Submit for approval"}</button>
        </form>
      </section>}
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">EXPENSE REGISTER</div><h2>Claims and approvals</h2></div></div>
        <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Description</th><th>Category / vendor</th><th>Event</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
          {expenses.expenses.map((expense) => <tr key={expense.id}><td>{formatDate(expense.spent_on)}</td><td>{expense.description}<small>{expense.reference ? ` · ${expense.reference}` : ""}</small></td><td>{expense.category}<small>{expense.vendor ?? "—"}</small></td><td>{expense.event?.name ?? "General"}</td><td>{money(expense.amount)}</td><td><span className="status-badge">{expense.status}</span>{expense.review_note && <small>{expense.review_note}</small>}</td><td><ExpenseActions expense={expense} userId={user.id} saving={saving} canApprove={hasPermission(user, "expenses.approve")} canPay={hasPermission(user, "expenses.pay")} paymentId={paymentId} paymentReference={paymentReference} setPaymentId={setPaymentId} setPaymentReference={setPaymentReference} action={action} /></td></tr>)}
          {!expenses.expenses.length && <tr><td colSpan={7}>No expenses recorded.</td></tr>}
        </tbody></table></div>
      </section>
    </>}

    {assets && <>
      <section className="dashboard-stat-grid">
        <Stat label="REGISTERED" value={assets.report.count} caption={`Acquisition value ${money(assets.report.acquisition_value)}`} />
        <Stat label="ACTIVE" value={assets.report.active} caption="In service" />
        <Stat label="MAINTENANCE" value={assets.report.maintenance} caption={`${assets.report.lost} lost`} />
        <Stat label="DISPOSED" value={assets.report.disposed} caption="Retained in asset history" />
      </section>
      {canCreate && <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">REGISTER AN ASSET</div><h2>Add to asset register</h2></div></div>
        <form className="compact-form" onSubmit={(event) => void submit(event, "/assets")}>
          <div className="form-two-col"><label className="field-label">Asset tag<input name="asset_tag" maxLength={60} required /></label><label className="field-label">Asset name<input name="name" maxLength={180} required /></label></div>
          <div className="form-two-col"><label className="field-label">Category<input name="category" maxLength={80} required /></label><label className="field-label">Serial number<input name="serial_number" maxLength={100} /></label></div>
          <div className="form-two-col"><label className="field-label">Acquired on<input name="acquired_on" type="date" defaultValue={today} required /></label><label className="field-label">Acquisition cost<input name="acquisition_cost" type="number" min="0" step="0.01" defaultValue="0" required /></label></div>
          <div className="form-two-col"><label className="field-label">Location<input name="location" maxLength={180} /></label><label className="field-label">Condition<select name="condition" defaultValue="good"><option value="new">New</option><option value="good">Good</option><option value="fair">Fair</option><option value="poor">Poor</option></select></label></div>
          <div className="form-two-col"><label className="field-label">Warranty ends<input name="warranty_ends_on" type="date" /></label><label className="field-label">Custodian<select name="custodian_employee_id" defaultValue=""><option value="">Unassigned</option>{assets.employees.map((employee) => <option key={employee.id} value={employee.id}>{employeeName(employee)}{employee.employee_id ? ` · ${employee.employee_id}` : ""}</option>)}</select></label></div>
          <label className="field-label">Notes<textarea name="notes" maxLength={5000} rows={2} /></label>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Register asset"}</button>
        </form>
      </section>}
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">ASSET LIFECYCLE</div><h2>Register and history</h2></div></div>
        <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Asset</th><th>Category / serial</th><th>Acquired / value</th><th>Location / custodian</th><th>Condition / status</th><th>Lifecycle</th><th>Record update</th></tr></thead><tbody>
          {assets.assets.map((asset) => <tr key={asset.id}><td><strong>{asset.name}</strong><small>{asset.asset_tag}</small></td><td>{asset.category}<small>{asset.serial_number ?? "—"}</small></td><td>{formatDate(asset.acquired_on)}<small>{money(asset.acquisition_cost)}</small></td><td>{asset.location ?? "—"}<small>{asset.custodian ? employeeName(asset.custodian) : "Unassigned"}</small></td><td>{asset.condition}<small><span className="status-badge">{asset.status}</span></small></td><td><details><summary>{asset.lifecycle_events.length} records</summary>{asset.lifecycle_events.map((record) => <p key={record.id}><strong>{formatDate(record.occurred_on)} · {record.event_type}</strong>{record.from_status ? ` · ${record.from_status} → ${record.to_status}` : ""}{record.from_condition || record.to_condition ? ` · ${record.from_condition ?? "—"} → ${record.to_condition ?? "—"}` : ""}<br />{record.location ?? ""} {record.notes ?? ""}</p>)}</details></td><td>{asset.status !== "disposed" && hasPermission(user, "assets.edit") && <form className="compact-form" onSubmit={(event) => void submit(event, `/assets/${asset.id}/lifecycle`, "POST", undefined, true)}><select name="status" defaultValue={asset.status}><option value="active">Active</option><option value="maintenance">Maintenance</option><option value="lost">Lost</option><option value="disposed">Disposed</option></select><select name="condition" defaultValue={asset.condition}><option value="new">New</option><option value="good">Good</option><option value="fair">Fair</option><option value="poor">Poor</option></select><input name="location" aria-label="New asset location" placeholder="Location" defaultValue={asset.location ?? ""} /><select name="custodian_employee_id" defaultValue={asset.custodian_employee_id ?? ""}><option value="">Unassigned</option>{assets.employees.map((employee) => <option key={employee.id} value={employee.id}>{employeeName(employee)}</option>)}</select><input name="occurred_on" aria-label="Lifecycle date" type="date" defaultValue={today} required /><input name="notes" aria-label="Lifecycle note" placeholder="Maintenance / change note" /><button className="button button-secondary" disabled={saving}>Record update</button></form>}</td></tr>)}
          {!assets.assets.length && <tr><td colSpan={7}>No assets registered.</td></tr>}
        </tbody></table></div>
      </section>
    </>}

    {events && <>
      {canCreate && <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">EVENT PLANNING</div><h2>Plan a school event</h2></div></div>
        <form className="compact-form" onSubmit={(event) => void submit(event, "/events")}>
          <div className="form-two-col"><label className="field-label">Event name<input name="name" maxLength={180} required /></label><label className="field-label">Category<input name="category" maxLength={80} placeholder="Sports, cultural, academic…" /></label></div>
          <div className="form-two-col"><label className="field-label">Starts on<input name="starts_on" type="date" required /></label><label className="field-label">Ends on<input name="ends_on" type="date" required /></label></div>
          <div className="form-two-col"><label className="field-label">Venue<input name="venue" maxLength={180} /></label><label className="field-label">Planned budget<input name="budget" type="number" min="0" step="0.01" defaultValue="0" required /></label></div>
          <label className="field-label">Description<textarea name="description" maxLength={5000} rows={2} /></label>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create planned event"}</button>
        </form>
      </section>}
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">EVENT CALENDAR & BUDGETS</div><h2>Events and linked expenses</h2></div></div>
        <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Event</th><th>Dates / venue</th><th>Budget</th><th>Committed / pending</th><th>Variance</th><th>Status</th><th>Planning</th></tr></thead><tbody>
          {events.events.map((event) => <tr key={event.id}><td><strong>{event.name}</strong><small>{event.category ?? "Event"}</small></td><td>{formatDate(event.starts_on)} – {formatDate(event.ends_on)}<small>{event.venue ?? "Venue not set"}</small></td><td>{money(event.budget)}</td><td>{money(event.committed_expenses)} committed<small>{money(event.pending_amount ?? 0)} pending · {event.approved_expense_count} approved/paid</small></td><td>{event.budget_overrun ? <span className="status-badge status-badge-warning">Over budget by {money(Number(event.committed_expenses) - Number(event.budget))}</span> : money(Number(event.budget) - Number(event.committed_expenses))} available</td><td><span className="status-badge">{event.status}</span><small>{event.pending_expense_count} expense(s) pending review</small></td><td>{hasPermission(user, "events.edit") && !["completed", "cancelled"].includes(event.status) && <form className="inline-form" onSubmit={(formEvent) => void submit(formEvent, `/events/${event.id}`, "PATCH")}><input type="hidden" name="name" value={event.name} /><input type="hidden" name="category" value={event.category ?? ""} /><input type="hidden" name="description" value={event.description ?? ""} /><input type="hidden" name="venue" value={event.venue ?? ""} /><input type="hidden" name="starts_on" value={event.starts_on} /><input type="hidden" name="ends_on" value={event.ends_on} /><label className="field-label">Budget<input name="budget" aria-label={`Budget for ${event.name}`} type="number" min="0" step="0.01" defaultValue={event.budget} required /></label><select name="status" defaultValue={event.status}><option value="planned">Planned</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select><button className="button button-secondary" disabled={saving}>Update</button></form>}</td></tr>)}
          {!events.events.length && <tr><td colSpan={7}>No events planned.</td></tr>}
        </tbody></table></div>
        {events.events.some((event) => event.expenses.length) && <div className="bottom-grid">{events.events.filter((event) => event.expenses.length).map((event) => <section className="surface-card" key={`expenses-${event.id}`}><div className="card-heading"><div><div className="eyebrow">LINKED EXPENSES</div><h2>{event.name}</h2></div></div><div className="data-table-wrap"><table className="data-table"><thead><tr><th>Description</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>{event.expenses.map((expense) => <tr key={expense.id}><td>{expense.description}<small>{expense.category}</small></td><td>{formatDate(expense.spent_on)}</td><td>{money(expense.amount)}</td><td><span className="status-badge">{expense.status}</span></td></tr>)}</tbody></table></div></section>)}</div>}
      </section>
    </>}
  </div>;
}

function Stat({ label, value, caption }: { label: string; value: string | number; caption: string }) {
  return <article className="surface-card"><div className="eyebrow">{label}</div><strong>{value}</strong><span>{caption}</span></article>;
}

function ExpenseActions({ expense, userId, saving, canApprove, canPay, paymentId, paymentReference, setPaymentId, setPaymentReference, action }: {
  expense: Expense; userId: number; saving: boolean; canApprove: boolean; canPay: boolean; paymentId: number | null;
  paymentReference: string; setPaymentId: (id: number | null) => void; setPaymentReference: (reference: string) => void;
  action: (endpoint: string, message: string, body?: unknown) => Promise<void>;
}) {
  return <div className="button-row">
    {expense.status === "pending" && canApprove && expense.submitted_by !== userId && <>
      <button className="button button-secondary" disabled={saving} onClick={() => void action(`/expenses/${expense.id}/review`, "Expense approved.", { status: "approved" })}>Approve</button>
      <button className="button button-secondary" disabled={saving} onClick={() => void action(`/expenses/${expense.id}/review`, "Expense rejected.", { status: "rejected" })}>Reject</button>
    </>}
    {expense.status === "approved" && canPay && expense.submitted_by !== userId && (paymentId === expense.id
      ? <form onSubmit={(event) => { event.preventDefault(); void action(`/expenses/${expense.id}/pay`, "Expense payment recorded.", { payment_reference: paymentReference }); }}><input aria-label="Payment reference" value={paymentReference} onChange={(event) => setPaymentReference(event.target.value)} placeholder="Payment reference" /><button className="button button-secondary" disabled={saving}>Confirm paid</button></form>
      : <button className="button button-secondary" disabled={saving} onClick={() => setPaymentId(expense.id)}>Record payment</button>)}
  </div>;
}
