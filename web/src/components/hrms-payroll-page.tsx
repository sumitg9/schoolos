"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Employee = { id: number; employee_id: string | null; first_name: string; last_name: string | null; department: string | null };
type ComponentAmount = { name: string; amount: string };
type SalaryStructure = { id: number; effective_from: string; base_salary: string; allowances: ComponentAmount[]; deductions: ComponentAmount[]; employee: Employee };
type PayrollRecord = {
  id: number; pay_year: number; pay_month: number; base_salary: string; allowances: string; deductions: string;
  net_amount: string; status: "draft" | "approved" | "paid"; created_by: number;
  slip_number: string | null; reference: string | null;
  allowance_items: ComponentAmount[] | null; deduction_items: ComponentAmount[] | null; paid_at: string | null;
  employee: Employee;
};
type PayrollData = {
  records: PayrollRecord[]; employees: Employee[]; structures: SalaryStructure[];
  report: { records: number; draft: number; approved: number; paid: number; gross_amount: string; deductions: string; net_amount: string };
};
type ComponentForm = { name: string; amount: string };

const employeeName = (employee: Employee) => `${employee.first_name} ${employee.last_name ?? ""}`.trim();
const money = (amount: string | number) => Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = new Date();
const initialPeriod = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}`;

export function HrmsPayrollPage() {
  const { user } = useSession();
  const [data, setData] = useState<PayrollData | null>(null);
  const [period, setPeriod] = useState(initialPeriod);
  const [employeeId, setEmployeeId] = useState("");
  const [baseSalary, setBaseSalary] = useState("");
  const [effectiveFrom, setEffectiveFrom] = useState(today.toISOString().slice(0, 10));
  const [allowances, setAllowances] = useState<ComponentForm[]>([{ name: "", amount: "" }]);
  const [deductions, setDeductions] = useState<ComponentForm[]>([{ name: "", amount: "" }]);
  const [slip, setSlip] = useState<PayrollRecord | null>(null);
  const [referenceId, setReferenceId] = useState<number | null>(null);
  const [reference, setReference] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [year, month] = period.split("-");
      setData(await apiRequest<PayrollData>(`/payroll?pay_year=${year}&pay_month=${month}`));
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [period]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function submitStructure(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/payroll/structures", {
        method: "POST",
        body: {
          employee_id: employeeId,
          effective_from: effectiveFrom,
          base_salary: baseSalary,
          allowances: allowances.filter((item) => item.name.trim() && item.amount !== ""),
          deductions: deductions.filter((item) => item.name.trim() && item.amount !== ""),
        },
      });
      setNotice("Salary structure saved. Changes are effective on or after the date entered.");
      setBaseSalary("");
      setAllowances([{ name: "", amount: "" }]);
      setDeductions([{ name: "", amount: "" }]);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function submitPayroll(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const [year, month] = period.split("-");
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/payroll", { method: "POST", body: { employee_id: employeeId, pay_year: Number(year), pay_month: Number(month) } });
      setNotice("Draft payroll calculated from the salary structure effective on the first day of the selected month.");
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
      setReferenceId(null);
      setReference("");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function openSlip(recordId: number) {
    setError("");
    try {
      const response = await apiRequest<{ data: PayrollRecord }>(`/payroll/${recordId}/slip`);
      setSlip(response.data);
      window.setTimeout(() => window.print(), 100);
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  const canCreate = hasPermission(user, "payroll.create");
  const canApprove = hasPermission(user, "payroll.approve");
  const canPay = hasPermission(user, "payroll.pay");
  const [year, month] = period.split("-");

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">STAFF OPERATIONS</div><h1>Payroll<span className="heading-period">.</span></h1><p>Build effective-dated salary structures, calculate monthly payroll, and track approvals and payments.</p></div>
        <label className="field-label">Report period<input aria-label="Payroll report period" type="month" value={period} onChange={(event) => setPeriod(event.target.value)} required /></label>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {loading && <div className="loading-indicator">Loading payroll…</div>}
      {data && <>
        <section className="dashboard-stat-grid">
          <article className="surface-card"><div className="eyebrow">RECORDS</div><strong>{data.report.records}</strong><span>Pay slips in period</span></article>
          <article className="surface-card"><div className="eyebrow">GROSS PAY</div><strong>{money(data.report.gross_amount)}</strong><span>Base salary and allowances</span></article>
          <article className="surface-card"><div className="eyebrow">DEDUCTIONS</div><strong>{money(data.report.deductions)}</strong><span>Fixed salary components</span></article>
          <article className="surface-card"><div className="eyebrow">NET PAY</div><strong>{money(data.report.net_amount)}</strong><span>{data.report.paid} paid · {data.report.approved} approved · {data.report.draft} draft</span></article>
        </section>

        {canCreate && <div className="bottom-grid">
          <section className="surface-card setup-card">
            <div className="card-heading"><div><div className="eyebrow">COMPENSATION</div><h2>Set salary structure</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submitStructure(event)}>
              <label className="field-label">Employee<select value={employeeId} onChange={(event) => setEmployeeId(event.target.value)} required><option value="">Select employee</option>{data.employees.map((employee) => <option key={employee.id} value={employee.id}>{employeeName(employee)}{employee.employee_id ? ` · ${employee.employee_id}` : ""}</option>)}</select></label>
              <div className="form-two-col"><label className="field-label">Effective from<input type="date" value={effectiveFrom} onChange={(event) => setEffectiveFrom(event.target.value)} required /></label><label className="field-label">Monthly base salary<input type="number" min="0" step="0.01" value={baseSalary} onChange={(event) => setBaseSalary(event.target.value)} required /></label></div>
              <ComponentEditor title="Allowances" items={allowances} onChange={setAllowances} />
              <ComponentEditor title="Deductions" items={deductions} onChange={setDeductions} />
              <p className="muted-copy">Structures are versioned, not overwritten. The structure effective on the first day of a pay month is used for that entire month.</p>
              <button className="button button-primary" disabled={saving || !data.employees.length}>{saving ? "Saving…" : "Save salary structure"}</button>
            </form>
          </section>
          <section className="surface-card setup-card">
            <div className="card-heading"><div><div className="eyebrow">MONTHLY RUN</div><h2>Prepare payroll</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submitPayroll(event)}>
              <label className="field-label">Employee<select value={employeeId} onChange={(event) => setEmployeeId(event.target.value)} required><option value="">Select employee</option>{data.employees.map((employee) => <option key={employee.id} value={employee.id}>{employeeName(employee)}</option>)}</select></label>
              <p className="muted-copy">Pay period: {year}-{month}. Payroll is created as a draft and snapshots the effective salary components. Approved leave is tracked separately and is not deducted automatically.</p>
              <button className="button button-primary" disabled={saving || !data.employees.length}>{saving ? "Calculating…" : "Calculate draft payroll"}</button>
            </form>
          </section>
        </div>}

        <section className="surface-card">
          <div className="card-heading"><div><div className="eyebrow">SALARY STRUCTURES</div><h2>Effective-dated history</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Employee</th><th>Effective from</th><th>Base</th><th>Allowances</th><th>Deductions</th></tr></thead><tbody>
            {data.structures.map((structure) => <tr key={structure.id}><td>{employeeName(structure.employee)}</td><td>{formatDate(structure.effective_from)}</td><td>{money(structure.base_salary)}</td><td>{(structure.allowances ?? []).map((item) => `${item.name}: ${money(item.amount)}`).join(", ") || "—"}</td><td>{(structure.deductions ?? []).map((item) => `${item.name}: ${money(item.amount)}`).join(", ") || "—"}</td></tr>)}
            {!data.structures.length && <tr><td colSpan={5}>No salary structures recorded.</td></tr>}
          </tbody></table></div>
        </section>

        <section className="surface-card">
          <div className="card-heading"><div><div className="eyebrow">PAYROLL REGISTER & COLLECTION REPORT</div><h2>{year}-{month}</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Employee</th><th>Base</th><th>Allowances</th><th>Deductions</th><th>Net</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            {data.records.map((record) => <tr key={record.id}>
              <td>{employeeName(record.employee)}</td><td>{money(record.base_salary)}</td><td>{money(record.allowances)}</td><td>{money(record.deductions)}</td><td>{money(record.net_amount)}</td>
              <td><span className="status-badge">{record.status}</span></td>
              <td><div className="button-row">
                {record.status !== "draft" && <button className="button button-secondary" disabled={saving} onClick={() => void openSlip(record.id)}>Print slip</button>}
                {record.status === "draft" && canApprove && record.created_by !== user.id && <button className="button button-secondary" disabled={saving} onClick={() => void action(`/payroll/${record.id}/approve`, "Payroll approved and salary slip generated.")}>Approve</button>}
                {record.status === "approved" && canPay && record.created_by !== user.id && (referenceId === record.id
                  ? <form onSubmit={(event) => { event.preventDefault(); void action(`/payroll/${record.id}/pay`, "Payroll marked as paid.", { reference }); }}><input aria-label="Payment reference" value={reference} onChange={(event) => setReference(event.target.value)} placeholder="Payment reference" /><button className="button button-secondary" disabled={saving}>Confirm payment</button></form>
                  : <button className="button button-secondary" disabled={saving} onClick={() => setReferenceId(record.id)}>Record payment</button>)}
              </div></td>
            </tr>)}
            {!data.records.length && <tr><td colSpan={7}>No payroll records for this period.</td></tr>}
          </tbody></table></div>
        </section>
      </>}

      {slip && <section className="print-report-card salary-slip">
        <div className="no-print button-row"><button className="button button-secondary" onClick={() => window.print()}>Print / save as PDF</button><button className="button button-secondary" onClick={() => setSlip(null)}>Close slip</button></div>
        <div className="eyebrow">SALARY SLIP · {slip.slip_number}</div><h2>Salary statement</h2>
        <p><strong>Employee:</strong> {employeeName(slip.employee)} {slip.employee.employee_id ? `(${slip.employee.employee_id})` : ""}<br />
          <strong>Department:</strong> {slip.employee.department ?? "—"}<br /><strong>Pay period:</strong> {slip.pay_year}-{String(slip.pay_month).padStart(2, "0")}<br />
          <strong>Status:</strong> {slip.status}{slip.paid_at ? ` · Paid ${formatDateTime(slip.paid_at)}` : ""}</p>
        <table className="data-table"><thead><tr><th>Earnings</th><th>Amount</th><th>Deductions</th><th>Amount</th></tr></thead><tbody>
          <tr><td>Base salary</td><td>{money(slip.base_salary)}</td><td>—</td><td>—</td></tr>
          {Array.from({ length: Math.max(slip.allowance_items?.length ?? 0, slip.deduction_items?.length ?? 0) }, (_, index) => <tr key={index}><td>{slip.allowance_items?.[index]?.name ?? "—"}</td><td>{slip.allowance_items?.[index] ? money(slip.allowance_items[index].amount) : "—"}</td><td>{slip.deduction_items?.[index]?.name ?? "—"}</td><td>{slip.deduction_items?.[index] ? money(slip.deduction_items[index].amount) : "—"}</td></tr>)}
          <tr><th>Total earnings</th><th>{money(Number(slip.base_salary) + Number(slip.allowances))}</th><th>Total deductions</th><th>{money(slip.deductions)}</th></tr>
          <tr><th colSpan={3}>Net pay</th><th>{money(slip.net_amount)}</th></tr>
        </tbody></table>
      </section>}
    </div>
  );
}

function ComponentEditor({ title, items, onChange }: { title: string; items: ComponentForm[]; onChange: (items: ComponentForm[]) => void }) {
  function update(index: number, field: keyof ComponentForm, value: string) {
    onChange(items.map((item, current) => current === index ? { ...item, [field]: value } : item));
  }
  return <div className="compact-form"><div className="card-heading"><h3>{title}</h3><button className="button button-secondary" type="button" disabled={items.length >= 30} onClick={() => onChange([...items, { name: "", amount: "" }])}>Add component</button></div>
    {items.map((item, index) => <div className="form-two-col" key={index}><label className="field-label">{title} name<input maxLength={80} value={item.name} onChange={(event) => update(index, "name", event.target.value)} /></label><label className="field-label">Monthly amount<input type="number" min="0" step="0.01" value={item.amount} onChange={(event) => update(index, "amount", event.target.value)} /></label></div>)}
  </div>;
}
