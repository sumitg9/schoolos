"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/date";

type Student = { id: number; admission_number: string; first_name: string; last_name: string | null; total_charges: string | null; total_payments: string | null };
type FeeRecord = { id: number; description?: string; amount: string; due_on?: string; paid_at?: string; method?: string; reference?: string | null; student: Student };
type PaymentOrder = { id: number; provider: string; provider_order_id: string; provider_payment_id: string | null; amount: string; currency: string; status: string; created_at: string; student: Student };
type FeeStructure = { id: number; name: string; academic_session_id: number; school_class_id: number; late_fee_amount: string; status: string; items: { id: number; description: string; amount: string }[]; academic_session: { name: string }; school_class: { name: string } };
type FeeInvoice = { id: number; invoice_number: string; due_on: string; subtotal: string; concession_amount: string; concession_reason: string | null; late_fee_amount: string; total: string; paid: string; balance: string; status: string; student: Student; charges: { id: number; description: string; amount: string }[] };
type FeeData = { students: Student[]; charges: FeeRecord[]; payments: FeeRecord[]; payment_orders: PaymentOrder[]; structures: FeeStructure[]; invoices: FeeInvoice[] };
type ChargeForm = { student_id: string; description: string; amount: string; due_on: string };
type PaymentForm = { student_id: string; fee_invoice_id: string; amount: string; paid_at: string; method: string; reference: string; remarks: string };
type StructureItemForm = { description: string; amount: string };

const currentDate = new Date();
const today = `${currentDate.getFullYear()}-${String(currentDate.getMonth() + 1).padStart(2, "0")}-${String(currentDate.getDate()).padStart(2, "0")}`;
const emptyCharge: ChargeForm = { student_id: "", description: "", amount: "", due_on: today };
const emptyPayment: PaymentForm = { student_id: "", fee_invoice_id: "", amount: "", paid_at: today, method: "cash", reference: "", remarks: "" };
const emptyStructureItem: StructureItemForm = { description: "", amount: "" };
const formatAmount = (value: string | number) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const studentLabel = (student: Student) => `${student.first_name} ${student.last_name ?? ""} · ${student.admission_number}`;

export default function FeesPage() {
  const [data, setData] = useState<FeeData>({ students: [], charges: [], payments: [], payment_orders: [], structures: [], invoices: [] });
  const [sessions, setSessions] = useState<{ id: number; name: string }[]>([]);
  const [classes, setClasses] = useState<{ id: number; name: string; academic_session_id: number }[]>([]);
  const [structure, setStructure] = useState({ name: "", academic_session_id: "", school_class_id: "", late_fee_amount: "0" });
  const [structureItems, setStructureItems] = useState<StructureItemForm[]>([{ ...emptyStructureItem }]);
  const [invoiceDueDate, setInvoiceDueDate] = useState(today);
  const [concession, setConcession] = useState({ invoice_id: "", amount: "", reason: "" });
  const [report, setReport] = useState<{ from: string; to: string; collected: string; outstanding_total: string; payment_count: number; students_with_dues: number; dues: { student: Student; outstanding: string }[] } | null>(null);
  const [receipt, setReceipt] = useState<{ receipt_number: string; amount: string; paid_at: string; method: string; reference: string | null; student: Student; fee_invoice: { invoice_number: string } | null } | null>(null);
  const [reportDates, setReportDates] = useState({ from: "", to: "" });
  const [charge, setCharge] = useState(emptyCharge);
  const [payment, setPayment] = useState(emptyPayment);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [result, targets] = await Promise.all([
        apiRequest<FeeData>("/fees"),
        apiRequest<{ sessions: { id: number; name: string }[]; classes: { id: number; name: string; academic_session_id: number }[] }>("/fees/targets"),
      ]);
      setData(result);
      setSessions(targets.sessions);
      setClasses(targets.classes);
      setCharge((current) => ({ ...current, student_id: current.student_id || String(result.students[0]?.id ?? "") }));
      setPayment((current) => ({ ...current, student_id: current.student_id || String(result.students[0]?.id ?? "") }));
      setStructure((current) => {
        const academicSessionId = current.academic_session_id || String(targets.sessions[0]?.id ?? "");
        const firstClass = targets.classes.find((item) => item.academic_session_id === Number(academicSessionId));

        return {
          ...current,
          academic_session_id: academicSessionId,
          school_class_id: current.school_class_id || String(firstClass?.id ?? ""),
        };
      });
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  const selectedPaymentStudent = data.students.find((student) => String(student.id) === payment.student_id);
  const selectedPaymentInvoice = data.invoices.find((invoice) => String(invoice.id) === payment.fee_invoice_id);
  const studentInvoices = data.invoices.filter((invoice) => invoice.student.id === selectedPaymentStudent?.id);
  const outstanding = selectedPaymentStudent
    ? Number(selectedPaymentStudent.total_charges ?? 0) - Number(selectedPaymentStudent.total_payments ?? 0)
      - studentInvoices.reduce((total, invoice) => total + Number(invoice.concession_amount), 0)
      + studentInvoices.reduce((total, invoice) => total + Number(invoice.late_fee_amount), 0)
    : 0;
  const paymentBalance = selectedPaymentInvoice ? Number(selectedPaymentInvoice.balance) : outstanding;

  async function submitCharge(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/fees/charges", { method: "POST", body: { ...charge, student_id: Number(charge.student_id), amount: Number(charge.amount) } });
      setNotice("Fee charge posted.");
      setCharge({ ...emptyCharge, student_id: charge.student_id });
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function submitPayment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/fees/payments", {
        method: "POST",
        body: {
          ...payment,
          student_id: Number(payment.student_id),
          fee_invoice_id: payment.fee_invoice_id ? Number(payment.fee_invoice_id) : null,
          amount: Number(payment.amount),
          reference: payment.reference || null,
          remarks: payment.remarks || null,
        },
      });
      setNotice("Payment recorded.");
      setPayment({ ...emptyPayment, student_id: payment.student_id });
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function createStructure(event: FormEvent<HTMLFormElement>) {
      event.preventDefault();
      setSaving(true);
      setError("");
      setNotice("");
      try {
        await apiRequest("/fees/structures", {
          method: "POST",
          body: {
            ...structure,
            academic_session_id: Number(structure.academic_session_id),
            school_class_id: Number(structure.school_class_id),
            late_fee_amount: Number(structure.late_fee_amount),
            items: structureItems.map((item) => ({ ...item, amount: Number(item.amount) })),
          },
        });
        setNotice("Fee structure created.");
        setStructureItems([{ ...emptyStructureItem }]);
        await load();
      } catch (reason) {
        setError(errorMessage(reason));
      } finally {
        setSaving(false);
      }
    }

  async function issueInvoices(feeStructureId: number) {
      setSaving(true);
      setError("");
      setNotice("");
      try {
        const result = await apiRequest<{ issued: number; message: string }>(`/fees/structures/${feeStructureId}/invoices`, {
          method: "POST",
          body: { due_on: invoiceDueDate },
        });
        setNotice(result.message);
        await load();
      } catch (reason) {
        setError(errorMessage(reason));
      } finally {
        setSaving(false);
      }
    }

  async function applyConcession(event: FormEvent<HTMLFormElement>) {
      event.preventDefault();
      setSaving(true);
      setError("");
      setNotice("");
      try {
        await apiRequest(`/fees/invoices/${concession.invoice_id}/concession`, {
          method: "PATCH",
          body: { amount: Number(concession.amount), reason: concession.reason || null },
        });
        setNotice("Invoice concession updated.");
        await load();
      } catch (reason) {
        setError(errorMessage(reason));
      } finally {
        setSaving(false);
      }
    }

  async function loadReport() {
      setError("");
      try {
        const query = new URLSearchParams();
        if (reportDates.from) query.set("from", reportDates.from);
        if (reportDates.to) query.set("to", reportDates.to);
        setReport(await apiRequest<NonNullable<typeof report>>(`/fees/reports?${query}`));
      } catch (reason) {
        setError(errorMessage(reason));
      }
    }

  async function loadReceipt(paymentId: number) {
      setError("");
      try {
        const result = await apiRequest<{ data: NonNullable<typeof receipt> }>(`/fees/receipts/${paymentId}`);
        setReceipt(result.data);
      } catch (reason) {
        setError(errorMessage(reason));
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row"><div><div className="eyebrow">SCHOOL ACCOUNTS</div><h1>Fees & payments<span className="heading-period">.</span></h1><p>Post student charges and record received payments. Financial records are retained in the audit trail.</p></div><button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button></header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">FEE PLANS</div><h2>Create a class fee structure</h2></div></div>
        <form className="compact-form" onSubmit={createStructure}>
          <label className="field-label">Structure name<input required maxLength={180} value={structure.name} onChange={(event) => setStructure({ ...structure, name: event.target.value })} placeholder="e.g. Term 1 fees" /></label>
          <div className="form-two-col">
            <label className="field-label">Academic session<select required value={structure.academic_session_id} onChange={(event) => setStructure({ ...structure, academic_session_id: event.target.value, school_class_id: "" })}><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label>
            <label className="field-label">Class<select required value={structure.school_class_id} onChange={(event) => setStructure({ ...structure, school_class_id: event.target.value })}><option value="">Select class</option>{classes.filter((item) => item.academic_session_id === Number(structure.academic_session_id)).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          </div>
          <label className="field-label">One-time late fee after due date<input type="number" min="0" step="0.01" value={structure.late_fee_amount} onChange={(event) => setStructure({ ...structure, late_fee_amount: event.target.value })} /></label>
          <div className="card-heading"><div><div className="eyebrow">LINE ITEMS</div><h3>Fee items</h3></div><button type="button" className="button button-secondary" onClick={() => setStructureItems([...structureItems, { ...emptyStructureItem }])}>Add item</button></div>
          {structureItems.map((item, index) => <div className="form-two-col" key={index}>
            <label className="field-label">Description<input required maxLength={180} value={item.description} onChange={(event) => setStructureItems(structureItems.map((line, lineIndex) => lineIndex === index ? { ...line, description: event.target.value } : line))} /></label>
            <label className="field-label">Amount<input required type="number" min="0.01" step="0.01" value={item.amount} onChange={(event) => setStructureItems(structureItems.map((line, lineIndex) => lineIndex === index ? { ...line, amount: event.target.value } : line))} /></label>
          </div>)}
          <button className="button button-primary" disabled={saving || !classes.length}>{saving ? "Saving…" : "Create fee structure"}</button>
        </form>
      </section>
      <section className="surface-card">
        <div className="card-heading"><div><div className="eyebrow">INVOICING</div><h2>Issue invoices to enrolled students</h2></div></div>
        <label className="field-label">Invoice due date<input type="date" required value={invoiceDueDate} onChange={(event) => setInvoiceDueDate(event.target.value)} /></label>
        {!data.structures.length ? <p className="muted-copy">Create a fee structure before issuing invoices.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Structure</th><th>Session</th><th>Class</th><th>Items</th><th>Late fee</th><th></th></tr></thead><tbody>
          {data.structures.map((item) => <tr key={item.id}><td>{item.name}</td><td>{item.academic_session.name}</td><td>{item.school_class.name}</td><td>{item.items.length}</td><td>{formatAmount(item.late_fee_amount)}</td><td><button className="button button-secondary" disabled={saving || item.status === "archived"} onClick={() => void issueInvoices(item.id)}>Issue invoices</button></td></tr>)}
        </tbody></table></div>}
      </section>
      <section className="surface-card">
        <div className="card-heading"><div><div className="eyebrow">CONCESSIONS</div><h2>Apply an invoice concession</h2></div></div>
        {!data.invoices.length ? <p className="muted-copy">Invoices are required before concessions can be applied.</p> : <form className="compact-form" onSubmit={applyConcession}>
          <label className="field-label">Invoice<select required value={concession.invoice_id} onChange={(event) => setConcession({ ...concession, invoice_id: event.target.value })}><option value="">Select invoice</option>{data.invoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.invoice_number} · {studentLabel(invoice.student)} · balance {formatAmount(invoice.balance)}</option>)}</select></label>
          <div className="form-two-col"><label className="field-label">Concession amount<input required type="number" min="0" step="0.01" value={concession.amount} onChange={(event) => setConcession({ ...concession, amount: event.target.value })} /></label><label className="field-label">Reason (optional)<input maxLength={180} value={concession.reason} onChange={(event) => setConcession({ ...concession, reason: event.target.value })} /></label></div>
          <button className="button button-primary" disabled={saving || !concession.invoice_id}>{saving ? "Saving…" : "Save concession"}</button>
        </form>}
      </section>
      <div className="bottom-grid">
        <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">NEW FEE</div><h2>Post a student charge</h2></div></div>
          <form className="compact-form" onSubmit={submitCharge}>
            <label className="field-label">Student<select required value={charge.student_id} onChange={(event) => setCharge({ ...charge, student_id: event.target.value })}><option value="">Select student</option>{data.students.map((student) => <option key={student.id} value={student.id}>{studentLabel(student)}</option>)}</select></label>
            <label className="field-label">Description<input required maxLength={180} value={charge.description} onChange={(event) => setCharge({ ...charge, description: event.target.value })} /></label>
            <div className="form-two-col"><label className="field-label">Amount<input required type="number" min="0.01" step="0.01" value={charge.amount} onChange={(event) => setCharge({ ...charge, amount: event.target.value })} /></label><label className="field-label">Due date<input required type="date" value={charge.due_on} onChange={(event) => setCharge({ ...charge, due_on: event.target.value })} /></label></div>
            <button className="button button-primary" disabled={saving || !data.students.length}>{saving ? "Saving…" : "Post charge"}</button>
          </form>
        </section>
        <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">PAYMENT RECEIVED</div><h2>Record payment</h2></div></div>
          <form className="compact-form" onSubmit={submitPayment}>
            <label className="field-label">Invoice (optional)<select value={payment.fee_invoice_id} onChange={(event) => {
              const invoice = data.invoices.find((item) => String(item.id) === event.target.value);
              setPayment({ ...payment, fee_invoice_id: event.target.value, student_id: invoice ? String(invoice.student.id) : payment.student_id, amount: "" });
            }}><option value="">Unallocated / student balance</option>{data.invoices.filter((invoice) => Number(invoice.balance) > 0).map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.invoice_number} · {studentLabel(invoice.student)} · {formatAmount(invoice.balance)}</option>)}</select></label>
            <label className="field-label">Student<select required value={payment.student_id} onChange={(event) => setPayment({ ...payment, student_id: event.target.value, fee_invoice_id: "" })}><option value="">Select student</option>{data.students.map((student) => <option key={student.id} value={student.id}>{studentLabel(student)}</option>)}</select></label>
            <div className="empty-inline">Outstanding balance: <strong>{formatAmount(paymentBalance)}</strong></div>
            <div className="form-two-col"><label className="field-label">Amount<input required type="number" min="0.01" max={Math.max(paymentBalance, 0).toFixed(2)} step="0.01" value={payment.amount} onChange={(event) => setPayment({ ...payment, amount: event.target.value })} /></label><label className="field-label">Received date<input required type="date" value={payment.paid_at} onChange={(event) => setPayment({ ...payment, paid_at: event.target.value })} /></label></div>
            <label className="field-label">Method<select value={payment.method} onChange={(event) => setPayment({ ...payment, method: event.target.value })}><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="card">Card</option><option value="cheque">Cheque</option><option value="online">Online</option></select></label>
            <label className="field-label">Reference (optional)<input maxLength={100} value={payment.reference} onChange={(event) => setPayment({ ...payment, reference: event.target.value })} /></label>
            <button className="button button-primary" disabled={saving || !data.students.length || paymentBalance <= 0}>{saving ? "Saving…" : "Record payment"}</button>
          </form>
        </section>
        <section className="surface-card">
          <div className="card-heading"><div><div className="eyebrow">INVOICES</div><h2>Invoice ledger</h2></div></div>
          {!data.invoices.length ? <p className="muted-copy">No invoices have been issued.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Invoice</th><th>Student</th><th>Due</th><th>Total</th><th>Concession</th><th>Late fee</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>
            {data.invoices.map((invoice) => <tr key={invoice.id}><td>{invoice.invoice_number}</td><td>{studentLabel(invoice.student)}</td><td>{formatDate(invoice.due_on)}</td><td>{formatAmount(invoice.total)}</td><td>{formatAmount(invoice.concession_amount)}</td><td>{formatAmount(invoice.late_fee_amount)}</td><td>{formatAmount(invoice.paid)}</td><td>{formatAmount(invoice.balance)}</td><td><span className="status-badge">{invoice.status}</span></td></tr>)}
          </tbody></table></div>}
        </section>
      </div>
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">LEDGER</div><h2>Recent fee charges</h2></div></div>
        {!data.charges.length ? <p className="muted-copy">No charges have been posted.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Student</th><th>Description</th><th>Due</th><th>Amount</th></tr></thead><tbody>{data.charges.map((item) => <tr key={item.id}><td>{studentLabel(item.student)}</td><td>{item.description}</td><td>{formatDate(item.due_on)}</td><td>{formatAmount(item.amount)}</td></tr>)}</tbody></table></div>}
      </section>
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">RECEIPTS</div><h2>Recent payments</h2></div></div>
        {!data.payments.length ? <p className="muted-copy">No payments have been recorded.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Student</th><th>Date & time</th><th>Method</th><th>Reference</th><th>Amount</th><th></th></tr></thead><tbody>{data.payments.map((item) => <tr key={item.id}><td>{studentLabel(item.student)}</td><td>{formatDateTime(item.paid_at)}</td><td>{item.method?.replaceAll("_", " ")}</td><td>{item.reference ?? "—"}</td><td>{formatAmount(item.amount)}</td><td><button className="button button-secondary" onClick={() => void loadReceipt(item.id)}>Receipt</button></td></tr>)}</tbody></table></div>}
      </section>
      {receipt && <section className="surface-card print-report-card"><div className="card-heading"><div><div className="eyebrow">PAYMENT RECEIPT</div><h2>{receipt.receipt_number ?? "Receipt"}</h2></div><button className="button button-secondary no-print" onClick={() => window.print()}>Print receipt</button></div><p>Received from {studentLabel(receipt.student)}</p><p>Amount: <strong>{formatAmount(receipt.amount)}</strong></p><p>Paid on {formatDateTime(receipt.paid_at)} by {receipt.method.replaceAll("_", " ")}</p><p>Reference: {receipt.reference ?? "—"}</p>{receipt.fee_invoice && <p>Invoice: {receipt.fee_invoice.invoice_number}</p>}</section>}
      <section className="surface-card">
        <div className="card-heading"><div><div className="eyebrow">COLLECTION REPORT</div><h2>Collections and outstanding dues</h2></div></div>
        <div className="form-two-col"><label className="field-label">From<input type="date" value={reportDates.from} onChange={(event) => setReportDates({ ...reportDates, from: event.target.value })} /></label><label className="field-label">To<input type="date" value={reportDates.to} onChange={(event) => setReportDates({ ...reportDates, to: event.target.value })} /></label></div>
        <button className="button button-secondary" onClick={() => void loadReport()}>Run report</button>
        {report && <><div className="metric-grid"><article className="metric-card"><div className="metric-label">Collected</div><div className="metric-value">{formatAmount(report.collected)}</div><div className="metric-hint">{report.payment_count} payments in selected period</div></article><article className="metric-card"><div className="metric-label">Outstanding</div><div className="metric-value">{formatAmount(report.outstanding_total)}</div><div className="metric-hint">{report.students_with_dues} students with dues</div></article></div>
          {!!report.dues.length && <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Student</th><th>Admission number</th><th>Outstanding</th></tr></thead><tbody>{report.dues.map((row) => <tr key={row.student.id}><td>{studentLabel(row.student)}</td><td>{row.student.admission_number}</td><td>{formatAmount(row.outstanding)}</td></tr>)}</tbody></table></div>}
        </>}
      </section>
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">ONLINE CHECKOUT</div><h2>Razorpay orders</h2></div></div>
        {!data.payment_orders.length ? <p className="muted-copy">No online payment orders.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Student</th><th>Created</th><th>Amount</th><th>Status</th><th>Provider payment ID</th></tr></thead><tbody>{data.payment_orders.map((order) => <tr key={order.id}><td>{studentLabel(order.student)}</td><td>{formatDateTime(order.created_at)}</td><td>{order.currency} {formatAmount(order.amount)}</td><td><span className={order.status === "review" ? "status-badge status-badge-warning" : "status-badge"}>{order.status === "review" ? "Needs review" : order.status}</span></td><td>{order.provider_payment_id ?? "—"}</td></tr>)}</tbody></table></div>}
      </section>
    </div>
  );
}
