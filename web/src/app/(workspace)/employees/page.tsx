"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, downloadFile, errorMessage } from "@/lib/api";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Employee = {
  id: number;
  employee_id: string;
  first_name: string;
  last_name: string | null;
  email: string | null;
  department: string | null;
  designation: string | null;
  employment_type: string;
  is_teaching_staff: boolean;
  status: string;
};
type ImportError = { row: number; message: string };

const initialForm = {
  first_name: "", last_name: "", email: "", phone: "", department: "", designation: "",
  employment_type: "full_time", is_teaching_staff: false, joining_date: new Date().toISOString().slice(0, 10),
};

export default function EmployeesPage() {
  const { user } = useSession();
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [form, setForm] = useState(initialForm);
  const [file, setFile] = useState<File | null>(null);
  const [importErrors, setImportErrors] = useState<ImportError[]>([]);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreate = hasPermission(user, "employees.create");
  const canImport = hasPermission(user, "employees.import");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const result = await apiRequest<{ data: Employee[] }>(`/employees?per_page=100&search=${encodeURIComponent(search)}`);
      setEmployees(result.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function createEmployee(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    try {
      await apiRequest("/employees", {
        method: "POST",
        body: {
          ...form,
          last_name: form.last_name || null,
          email: form.email || null,
          phone: form.phone || null,
          department: form.department || null,
          designation: form.designation || null,
        },
      });
      setForm(initialForm);
      setNotice("Employee record created.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function deactivate(employee: Employee) {
    if (!window.confirm(`Deactivate ${employee.first_name} ${employee.last_name ?? ""}? Assignment history will be preserved.`)) return;
    setError("");
    try {
      await apiRequest(`/employees/${employee.id}`, { method: "DELETE" });
      setNotice(`${employee.employee_id} was deactivated.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function importCsv(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!file) return;
    const data = new FormData();
    data.set("file", file);
    setSaving(true);
    setError("");
    try {
      const result = await apiRequest<{ created_count: number; error_count: number; errors: ImportError[] }>("/employees/import", { method: "POST", body: data });
      setNotice(`Imported ${result.created_count} employee(s); ${result.error_count} row(s) need correction.`);
      setImportErrors(result.errors);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">HUMAN RESOURCES</div><h1>Employees<span className="heading-period">.</span></h1><p>Maintain staff records, employment status and teaching eligibility.</p></div>
        <div className="button-row">
          {hasPermission(user, "employees.export") && <button className="button button-secondary" onClick={() => void downloadFile("/employees/export", "employees.csv").catch((reason) => setError(errorMessage(reason)))}>Export CSV</button>}
          <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
        </div>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW RECORD</div><h2>Add employee</h2></div></div>
        <form className="compact-form" onSubmit={createEmployee}>
          <div className="form-two-col">
            <label className="field-label">First name<input value={form.first_name} onChange={(event) => setForm({ ...form, first_name: event.target.value })} required /></label>
            <label className="field-label">Last name<input value={form.last_name} onChange={(event) => setForm({ ...form, last_name: event.target.value })} /></label>
            <label className="field-label">Email<input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label>
            <label className="field-label">Phone<input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label>
            <label className="field-label">Department<input value={form.department} onChange={(event) => setForm({ ...form, department: event.target.value })} /></label>
            <label className="field-label">Designation<input value={form.designation} onChange={(event) => setForm({ ...form, designation: event.target.value })} /></label>
            <label className="field-label">Employment type<select value={form.employment_type} onChange={(event) => setForm({ ...form, employment_type: event.target.value })}><option value="full_time">Full time</option><option value="part_time">Part time</option><option value="contract">Contract</option><option value="temporary">Temporary</option></select></label>
            <label className="field-label">Joining date<input type="date" value={form.joining_date} onChange={(event) => setForm({ ...form, joining_date: event.target.value })} required /></label>
          </div>
          <label className="checkbox-line"><input type="checkbox" checked={form.is_teaching_staff} onChange={(event) => setForm({ ...form, is_teaching_staff: event.target.checked })} /> Teaching staff (eligible for class and subject assignments)</label>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create employee"}</button>
        </form>
        {canImport && <form className="compact-form" onSubmit={importCsv}>
          <div className="eyebrow">BULK IMPORT</div>
          <div className="button-row">
            <button type="button" className="button button-secondary" onClick={() => void downloadFile("/employees/import-template", "employee-import-template.csv").catch((reason) => setError(errorMessage(reason)))}>Download template</button>
            <label className="field-label">CSV file<input type="file" accept=".csv,text/csv" onChange={(event) => setFile(event.target.files?.[0] ?? null)} required /></label>
            <button className="button button-secondary" disabled={saving || !file}>Import CSV</button>
          </div>
        </form>}
        {importErrors.length > 0 && <ul className="notice notice-error" aria-label="CSV import row errors">{importErrors.map((item, index) => <li key={`${item.row}-${index}`}>Row {item.row}: {item.message}</li>)}</ul>}
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">STAFF DIRECTORY</div><h2>Employee records</h2></div><span className="count-pill">{employees.length}</span></div>
        <form className="button-row" onSubmit={(event) => { event.preventDefault(); void load(); }}>
          <label className="field-label">Search employees<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, employee number or email" /></label>
          <button className="button button-secondary">Search</button>
        </form>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>EMPLOYEE</th><th>ROLE</th><th>DEPARTMENT</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
          {loading ? <tr><td className="table-empty" colSpan={5}>Loading employee records…</td></tr> : employees.length === 0 ? <tr><td className="table-empty" colSpan={5}>No employees found.</td></tr> : employees.map((employee) => <tr key={employee.id}>
            <td><strong>{employee.first_name} {employee.last_name}</strong><small>{employee.employee_id} · {employee.email ?? "No email"}</small></td>
            <td>{employee.designation ?? (employee.is_teaching_staff ? "Teaching staff" : "Staff")}<small>{employee.employment_type.replace("_", " ")}</small></td>
            <td>{employee.department ?? "—"}</td><td>{employee.status}</td>
            <td>{hasPermission(user, "employees.edit") && employee.status === "active" && <button className="text-link" onClick={() => void deactivate(employee)}>Deactivate</button>}</td>
          </tr>)}
        </tbody></table></div>
      </section>
    </div>
  );
}
