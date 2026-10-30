"use client";

import { useCallback, useEffect, useState } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/date";
import { useSession } from "@/components/workspace-shell";
import Script from "next/script";

declare global {
  interface Window {
    Razorpay?: new (options: {
      key: string;
      amount: number;
      currency: string;
      name: string;
      description: string;
      order_id: string;
      handler: (response: { razorpay_payment_id: string; razorpay_order_id: string; razorpay_signature: string }) => void;
      modal: { ondismiss: () => void };
      theme: { color: string };
    }) => { open: () => void };
  }
}

type Child = { id: number; name: string; admission_number: string };
type Attendance = { attendance_date: string; status: string; remarks: string | null };
type FeeCharge = { id: number; description: string; amount: string; due_on: string };
type FeePayment = { id: number; amount: string; paid_at: string; method: string; reference: string | null; remarks: string | null; receipt_number: string | null };
type PortalInvoice = { id: number; invoice_number: string; due_on: string; total: number; paid: number; balance: number; status: string; charges: { description: string; amount: string }[] };
type PortalSubject = { subject: { name: string; code: string | null }; maximum_marks: number; passing_marks: number; marks_obtained: number | null; remarks: string | null };
type ReportCard = {
  exam_id: number; title: string; starts_on: string; academic_session: string | null; school_class: string | null;
  section: string | null; subjects: PortalSubject[]; total_obtained: number | null; total_maximum: number;
  percentage: number | null; grade: string | null; result: string;
};
type PortalData = {
  student: {
    id: number; admission_number: string; first_name: string; last_name: string | null; date_of_birth: string | null;
    gender: string | null; admission_date: string; status: string;
    enrollments: { academic_session: { name: string }; school_class: { name: string }; section: { name: string } | null; roll_number: string | null; status: string }[];
  };
  attendance: Attendance[];
  fees: { total_charges: number; total_payments: number; balance: number; charges: FeeCharge[]; payments: FeePayment[]; invoices: PortalInvoice[]; online_payment_enabled: boolean; payment_orders: { id: number; amount: string; currency: string; status: string; created_at: string; paid_at: string | null }[] };
  report_cards: ReportCard[];
  announcements: { id: number; title: string; body: string; publish_at: string | null; created_at: string }[];
};

const amount = (value: number | string) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function PortalPage() {
  const { user } = useSession();
  const [children, setChildren] = useState<Child[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const [data, setData] = useState<PortalData | null>(null);
  const [selectedReportId, setSelectedReportId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [paymentNotice, setPaymentNotice] = useState("");
  const [paymentBusy, setPaymentBusy] = useState(false);
  const [receipt, setReceipt] = useState<FeePayment & { student: { first_name: string; last_name: string | null }; fee_invoice: { invoice_number: string } | null } | null>(null);
  const isParent = user.roles.some((role) => role.name === "parent");

  const loadChildren = useCallback(async () => {
    setError("");
    try {
      const result = await apiRequest<{ data: Child[] }>("/portal/children");
      setChildren(result.data);
      setSelectedId((current) => current && result.data.some((child) => String(child.id) === current)
        ? current
        : String(result.data[0]?.id ?? ""));
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }, []);

  useEffect(() => {
    void Promise.resolve().then(loadChildren).finally(() => setLoading(false));
  }, [loadChildren]);

  useEffect(() => {
    if (!selectedId) return;
    let active = true;
    apiRequest<{ data: PortalData }>(`/portal/students/${selectedId}`)
      .then(({ data: result }) => {
        if (active) {
          setData(result);
          setSelectedReportId(result.report_cards[0]?.exam_id ?? null);
        }
      })
      .catch((reason: unknown) => { if (active) setError(errorMessage(reason)); });
    return () => { active = false; };
  }, [selectedId]);

  const currentData = data?.student.id === Number(selectedId) ? data : null;
  const selectedReport = currentData?.report_cards.find((card) => card.exam_id === selectedReportId);

  async function payOnline(invoiceId?: number) {
    if (!selectedId) return;
    setError("");
    setPaymentNotice("");
    setPaymentBusy(true);
    try {
      const { data: order } = await apiRequest<{ data: { order_id: string; amount: number; currency: string; key_id: string; student_name: string } }>("/portal/payments/orders", {
        method: "POST",
        body: { student_id: Number(selectedId), ...(invoiceId ? { invoice_id: invoiceId } : {}) },
      });
      if (!window.Razorpay) {
        throw new Error("Secure checkout did not load. Check your connection and try again.");
      }
      const checkout = new window.Razorpay({
        key: order.key_id,
        amount: order.amount,
        currency: order.currency,
        name: "School fee payment",
        description: invoiceId && currentData
          ? `Invoice ${currentData.fees.invoices.find((invoice) => invoice.id === invoiceId)?.invoice_number ?? ""}`
          : `Fees for ${order.student_name}`,
        order_id: order.order_id,
        handler: () => {
          setPaymentBusy(false);
          setPaymentNotice("Payment submitted. The fee balance will update after the school receives confirmation from Razorpay.");
          window.setTimeout(() => window.location.reload(), 5000);
        },
        modal: { ondismiss: () => { setPaymentBusy(false); window.location.reload(); } },
        theme: { color: "#315e4b" },
      });
      checkout.open();
    } catch (reason) {
      setError(errorMessage(reason));
      setPaymentBusy(false);
    }
  }

  async function loadReceipt(paymentId: number) {
    setError("");
    try {
      const result = await apiRequest<{ data: NonNullable<typeof receipt> }>(`/portal/receipts/${paymentId}`);
      setReceipt(result.data);
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  return (
    <div className="page-stack">
      <Script src="https://checkout.razorpay.com/v1/checkout.js" strategy="afterInteractive" />
      <header className="page-title-row">
        <div><div className="eyebrow">{isParent ? "FAMILY PORTAL" : "STUDENT PORTAL"}</div><h1>{isParent ? "Your family" : "My school profile"}<span className="heading-period">.</span></h1><p>Profile, attendance, results, fee history and school announcements.</p></div>
        {children.length > 1 && <label className="field-label">Student<select value={selectedId} onChange={(event) => { setSelectedId(event.target.value); setReceipt(null); }}>{children.map((child) => <option key={child.id} value={child.id}>{child.name} · {child.admission_number}</option>)}</select></label>}
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {loading ? <div className="loading-indicator">Loading your portal…</div> : !children.length ? (
        <section className="surface-card"><h2>No linked student record</h2><p className="muted-copy">Contact the school office to confirm your portal activation code and student linkage.</p></section>
      ) : !currentData ? (
        <div className="loading-indicator">Loading student information…</div>
      ) : (
        <>
          <section className="metric-grid">
            <article className="metric-card"><div className="metric-label">Student</div><div className="metric-value">{currentData.student.first_name} {currentData.student.last_name ?? ""}</div><div className="metric-hint">Admission {currentData.student.admission_number}</div></article>
            <article className="metric-card"><div className="metric-label">Current class</div><div className="metric-value">{currentData.student.enrollments.find((enrollment) => enrollment.status === "active")?.school_class.name ?? "—"}</div><div className="metric-hint">{currentData.student.enrollments.find((enrollment) => enrollment.status === "active")?.section?.name ?? "No active section"}</div></article>
            <article className="metric-card"><div className="metric-label">Attendance records</div><div className="metric-value">{currentData.attendance.length}</div><div className="metric-hint">Most recent records shown below</div></article>
            <article className="metric-card"><div className="metric-label">Outstanding fees</div><div className="metric-value">{amount(currentData.fees.balance)}</div><div className="metric-hint">Charges less recorded payments</div></article>
          </section>

          <section className="surface-card">
            <div className="card-heading"><div><div className="eyebrow">STUDENT PROFILE</div><h2>School record</h2></div></div>
            <div className="data-table-wrap"><table className="data-table"><tbody>
              <tr><th>Full name</th><td>{currentData.student.first_name} {currentData.student.last_name ?? ""}</td><th>Status</th><td>{currentData.student.status}</td></tr>
              <tr><th>Date of birth</th><td>{formatDate(currentData.student.date_of_birth)}</td><th>Gender</th><td>{currentData.student.gender ?? "—"}</td></tr>
              <tr><th>Admission date</th><td>{formatDate(currentData.student.admission_date)}</td><th>Admission number</th><td>{currentData.student.admission_number}</td></tr>
            </tbody></table></div>
          </section>

          <section className="surface-card">
            <div className="card-heading"><div><div className="eyebrow">ATTENDANCE</div><h2>Attendance history</h2></div></div>
            {!currentData.attendance.length ? <p className="muted-copy">No attendance records have been entered yet.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Status</th><th>Remarks</th></tr></thead><tbody>
              {currentData.attendance.map((entry, index) => <tr key={`${entry.attendance_date}-${index}`}><td>{formatDate(entry.attendance_date)}</td><td><span className="status-badge">{entry.status}</span></td><td>{entry.remarks ?? "—"}</td></tr>)}
            </tbody></table></div>}
          </section>

          <section className="surface-card">
            <div className="card-heading"><div><div className="eyebrow">EXAMINATIONS</div><h2>Results & report cards</h2></div></div>
            {!currentData.report_cards.length ? <p className="muted-copy">Published exam results will appear here.</p> : <>
              <label className="field-label">Report card<select value={selectedReportId ?? ""} onChange={(event) => setSelectedReportId(Number(event.target.value))}>{currentData.report_cards.map((card) => <option key={card.exam_id} value={card.exam_id}>{card.title} · {card.academic_session}</option>)}</select></label>
              {selectedReport && <div className="print-report-card">
                <div className="no-print button-row"><button className="button button-secondary" onClick={() => window.print()}>Download / print report card</button></div>
                <h2>{selectedReport.school_class} · {currentData.student.first_name} {currentData.student.last_name ?? ""}</h2>
                <p>{selectedReport.title} · {selectedReport.academic_session}</p>
                <p>Admission: {currentData.student.admission_number} · Section: {selectedReport.section ?? "—"}</p>
                <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Subject</th><th>Obtained</th><th>Maximum</th><th>Passing</th><th>Remarks</th></tr></thead><tbody>
                  {selectedReport.subjects.map((subject) => <tr key={subject.subject.code ?? subject.subject.name}><td>{subject.subject.name}</td><td>{subject.marks_obtained ?? "Pending"}</td><td>{subject.maximum_marks}</td><td>{subject.passing_marks}</td><td>{subject.remarks ?? "—"}</td></tr>)}
                </tbody></table></div>
                <p><strong>Result:</strong> {selectedReport.result} · <strong>Grade:</strong> {selectedReport.grade ?? "Pending"} · <strong>Percentage:</strong> {selectedReport.percentage === null ? "Pending" : `${selectedReport.percentage}%`}</p>
              </div>}
            </>}
          </section>

          <section className="surface-card">
            <div className="card-heading"><div><div className="eyebrow">FEE ACCOUNT</div><h2>Balances & payment history</h2></div></div>
            <div className="metric-grid"><article className="metric-card"><div className="metric-label">Total charges</div><div className="metric-value">{amount(currentData.fees.total_charges)}</div></article><article className="metric-card"><div className="metric-label">Paid</div><div className="metric-value">{amount(currentData.fees.total_payments)}</div></article><article className="metric-card"><div className="metric-label">Balance</div><div className="metric-value">{amount(currentData.fees.balance)}</div></article></div>
            {paymentNotice && <div className="notice notice-success" role="status">{paymentNotice}</div>}
            {currentData.fees.online_payment_enabled && currentData.fees.balance > 0 && <button className="button button-primary" disabled={paymentBusy} onClick={() => void payOnline()}>{paymentBusy ? "Waiting for checkout…" : `Pay online · ${amount(currentData.fees.balance)}`}</button>}
            {!!currentData.fees.invoices.length && <><h3>Invoices</h3><div className="data-table-wrap"><table className="data-table"><thead><tr><th>Invoice</th><th>Due</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead><tbody>
              {currentData.fees.invoices.map((invoice) => <tr key={invoice.id}><td>{invoice.invoice_number}</td><td>{formatDate(invoice.due_on)}</td><td>{amount(invoice.total)}</td><td>{amount(invoice.paid)}</td><td>{amount(invoice.balance)}</td><td><span className="status-badge">{invoice.status}</span></td><td>{currentData.fees.online_payment_enabled && invoice.balance > 0 && <button className="button button-secondary" disabled={paymentBusy} onClick={() => void payOnline(invoice.id)}>Pay invoice</button>}</td></tr>)}
            </tbody></table></div></>}
            {!!currentData.fees.payment_orders.length && <><h3>Online payment status</h3><div className="data-table-wrap"><table className="data-table"><thead><tr><th>Created</th><th>Amount</th><th>Status</th></tr></thead><tbody>{currentData.fees.payment_orders.map((order) => <tr key={order.id}><td>{formatDateTime(order.created_at)}</td><td>{order.currency} {amount(order.amount)}</td><td>{order.status === "review" ? "Needs school review" : order.status}</td></tr>)}</tbody></table></div></>}
            <h3>Charges</h3>
            {!currentData.fees.charges.length ? <p className="muted-copy">No fee charges have been posted.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Description</th><th>Due</th><th>Amount</th></tr></thead><tbody>
              {currentData.fees.charges.map((charge) => <tr key={charge.id}><td>{charge.description}</td><td>{formatDate(charge.due_on)}</td><td>{amount(charge.amount)}</td></tr>)}
            </tbody></table></div>}
            <h3>Payments</h3>
            {!currentData.fees.payments.length ? <p className="muted-copy">No payments have been recorded.</p> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th><th>Receipt</th></tr></thead><tbody>
              {currentData.fees.payments.map((payment) => <tr key={payment.id}><td>{formatDateTime(payment.paid_at)}</td><td>{payment.method.replaceAll("_", " ")}</td><td>{payment.reference ?? "—"}</td><td>{amount(payment.amount)}</td><td><button className="button button-secondary" onClick={() => void loadReceipt(payment.id)}>View / print</button></td></tr>)}
            </tbody></table></div>}
          </section>

          <section className="surface-card">
            <div className="card-heading"><div><div className="eyebrow">SCHOOL COMMUNICATIONS</div><h2>Announcements</h2></div></div>
            {!currentData.announcements.length ? <p className="muted-copy">No current announcements.</p> : <div className="notice-list">
              {currentData.announcements.map((notice) => <article className="notice-item" key={notice.id}><div className="notice-item-heading"><strong>{notice.title}</strong><span>{formatDateTime(notice.publish_at ?? notice.created_at)}</span></div><p>{notice.body}</p></article>)}
            </div>}
          </section>
          {receipt && <section className="surface-card print-report-card"><div className="card-heading"><div><div className="eyebrow">PAYMENT RECEIPT</div><h2>{receipt.receipt_number ?? "Receipt"}</h2></div><button className="button button-secondary no-print" onClick={() => window.print()}>Print receipt</button></div><p>Received for {receipt.student.first_name} {receipt.student.last_name ?? ""}</p><p>Amount: <strong>{amount(receipt.amount)}</strong></p><p>Paid on {formatDateTime(receipt.paid_at)} by {receipt.method.replaceAll("_", " ")}</p><p>Reference: {receipt.reference ?? "—"}</p>{receipt.fee_invoice && <p>Invoice: {receipt.fee_invoice.invoice_number}</p>}</section>}
        </>
      )}
    </div>
  );
}
