"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/date";

type ModuleName = "library" | "transport" | "inventory" | "notifications";
type Person = { id: number; first_name: string; last_name: string | null; admission_number?: string; employee_id?: string };
type LibraryBook = { id: number; title: string; isbn: string | null; total_copies: number; available_copies: number };
type LibraryLoan = { id: number; status: string; issued_on: string; due_on: string; book: { title: string }; student: Person | null; employee: Person | null };
type LibraryData = { books: LibraryBook[]; loans: LibraryLoan[]; students: Person[]; employees: Person[] };
type Vehicle = { id: number; registration_number: string; capacity: number; status: "active" | "maintenance" | "inactive"; description: string | null; driver_employee_id: number | null; driver?: Person | null };
type TransportRoute = { id: number; name: string; stops: string; monthly_fee: string; status: "active" | "inactive"; active_students: number; vehicle: Vehicle | null };
type Assignment = { id: number; pickup_point: string | null; route: TransportRoute; student: Person };
type TransportData = { vehicles: Vehicle[]; routes: TransportRoute[]; assignments: Assignment[]; students: Person[]; employees: Person[] };
type InventoryItem = { id: number; name: string; sku: string | null; unit: string; quantity: number; reorder_level: number };
type InventoryTransaction = { id: number; type: string; quantity: number; description: string | null; created_at: string; item: InventoryItem };
type InventoryData = { items: InventoryItem[]; transactions: InventoryTransaction[] };
type Delivery = { id: number; channel: string; recipient: string; subject: string | null; status: string; failure_reason: string | null; sent_at: string | null; created_at: string };
type NotificationData = { data: { data: Delivery[] } };
type ModuleData = LibraryData | TransportData | InventoryData | NotificationData;

const personName = (person: Person) => `${person.first_name} ${person.last_name ?? ""}`.trim();
const money = (value: string | number) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const rows = <T,>(value: unknown): T[] => Array.isArray(value) ? value as T[] : [];

export function AdvancedOperationsPage({ module }: { module: ModuleName }) {
  const [data, setData] = useState<ModuleData | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      if (module === "library") setData(await apiRequest<LibraryData>("/library"));
      if (module === "transport") setData(await apiRequest<TransportData>("/transport"));
      if (module === "inventory") setData(await apiRequest<InventoryData>("/inventory"));
      if (module === "notifications") setData(await apiRequest<NotificationData>("/notifications"));
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [module]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function submit(event: FormEvent<HTMLFormElement>, endpoint: string, method: "POST" | "PATCH" = "POST", includeEmpty = false) {
    event.preventDefault();
    const form = event.currentTarget;
    const formEntries = [...new FormData(form)];
    const body = Object.fromEntries(includeEmpty ? formEntries : formEntries.filter(([, value]) => value !== ""));
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

  async function action(endpoint: string, success: string) {
    setError("");
    setNotice("");
    try {
      await apiRequest(endpoint, { method: "POST", body: {} });
      setNotice(success);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  const library = data as LibraryData | null;
  const transport = data as TransportData | null;
  const inventory = data as InventoryData | null;
  const notifications = data as NotificationData | null;
  const titles: Record<ModuleName, { eyebrow: string; title: string; description: string }> = {
    library: { eyebrow: "LEARNING RESOURCES", title: "Library", description: "Manage book copies, issue loans, and record returns." },
    transport: { eyebrow: "STUDENT SERVICES", title: "Transport", description: "Set up vehicles and routes, then assign students to active routes." },
    inventory: { eyebrow: "SCHOOL OPERATIONS", title: "Inventory", description: "Track stock receipts, issues, and current reorder levels." },
    notifications: { eyebrow: "COMMUNICATIONS", title: "Notifications", description: "Send individual email, SMS, and WhatsApp messages and review delivery status." },
  };
  const title = titles[module];

  return (
    <div className="page-stack">
      <header className="page-title-row"><div><div className="eyebrow">{title.eyebrow}</div><h1>{title.title}<span className="heading-period">.</span></h1><p>{title.description}</p></div><button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button></header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {loading && <div className="loading-indicator">Loading {title.title.toLowerCase()}…</div>}

      {module === "library" && library && <>
        <div className="bottom-grid">
          <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">CATALOGUE</div><h2>Add a book</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submit(event, "/library/books")}>
              <label className="field-label">Title<input name="title" required maxLength={200} /></label>
              <div className="form-two-col"><label className="field-label">Author<input name="author" maxLength={180} /></label><label className="field-label">ISBN<input name="isbn" maxLength={32} /></label></div>
              <div className="form-two-col"><label className="field-label">Category<input name="category" maxLength={100} /></label><label className="field-label">Copies<input name="total_copies" type="number" min="1" defaultValue="1" required /></label></div>
              <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Add book"}</button>
            </form>
          </section>
          <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">CIRCULATION</div><h2>Issue a book</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submit(event, "/library/loans")}>
              <label className="field-label">Book<select name="book_id" required defaultValue=""><option value="" disabled>Select book</option>{library.books.filter((book) => book.available_copies > 0).map((book) => <option key={book.id} value={book.id}>{book.title} ({book.available_copies} available)</option>)}</select></label>
              <label className="field-label">Student (choose one borrower)<select name="student_id" defaultValue=""><option value="">Not a student</option>{library.students.map((person) => <option key={person.id} value={person.id}>{personName(person)}</option>)}</select></label>
              <label className="field-label">Employee (choose one borrower)<select name="employee_id" defaultValue=""><option value="">Not an employee</option>{library.employees.map((person) => <option key={person.id} value={person.id}>{personName(person)}</option>)}</select></label>
              <div className="form-two-col"><label className="field-label">Issue date<input name="issued_on" type="date" required /></label><label className="field-label">Due date<input name="due_on" type="date" required /></label></div>
              <button className="button button-primary" disabled={saving || !library.books.some((book) => book.available_copies > 0)}>{saving ? "Saving…" : "Issue book"}</button>
            </form>
          </section>
        </div>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">BOOKS</div><h2>Library catalogue</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Title</th><th>ISBN</th><th>Total copies</th><th>Available</th></tr></thead><tbody>{library.books.map((book) => <tr key={book.id}><td>{book.title}</td><td>{book.isbn ?? "—"}</td><td>{book.total_copies}</td><td>{book.available_copies}</td></tr>)}{!library.books.length && <tr><td colSpan={4}>No books have been added.</td></tr>}</tbody></table></div>
        </section>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">LOANS</div><h2>Recent circulation</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Book</th><th>Borrower</th><th>Issued</th><th>Due</th><th>Status</th><th>Action</th></tr></thead><tbody>{library.loans.map((loan) => <tr key={loan.id}><td>{loan.book.title}</td><td>{loan.student ? personName(loan.student) : loan.employee ? personName(loan.employee) : "—"}</td><td>{formatDate(loan.issued_on)}</td><td>{formatDate(loan.due_on)}</td><td>{loan.status}</td><td>{loan.status === "issued" && <button className="button button-secondary" disabled={saving} onClick={() => void action(`/library/loans/${loan.id}/return`, "Book returned.")}>Return</button>}</td></tr>)}{!library.loans.length && <tr><td colSpan={6}>No loans recorded.</td></tr>}</tbody></table></div>
        </section>
      </>}

      {module === "transport" && transport && <>
        <div className="bottom-grid">
          <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">FLEET</div><h2>Add a vehicle</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submit(event, "/transport/vehicles")}>
              <label className="field-label">Registration number<input name="registration_number" required maxLength={40} /></label>
              <div className="form-two-col"><label className="field-label">Capacity<input name="capacity" type="number" min="1" required /></label><label className="field-label">Description<input name="description" maxLength={150} /></label></div>
              <label className="field-label">Driver<select name="driver_employee_id" defaultValue=""><option value="">Unassigned</option>{transport.employees.map((person) => <option key={person.id} value={person.id}>{personName(person)}</option>)}</select></label>
              <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Add vehicle"}</button>
            </form>
          </section>
          <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">ROUTES</div><h2>Create a route</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submit(event, "/transport/routes")}>
              <label className="field-label">Route name<input name="name" required maxLength={150} /></label>
              <label className="field-label">Stops (in route order)<textarea name="stops" required maxLength={5000} rows={3} /></label>
              <div className="form-two-col"><label className="field-label">Monthly fee<input name="monthly_fee" type="number" min="0" step="0.01" required defaultValue="0" /></label><label className="field-label">Vehicle<select name="vehicle_id" defaultValue=""><option value="">No vehicle</option>{transport.vehicles.filter((vehicle) => vehicle.status === "active").map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.registration_number} · capacity {vehicle.capacity}</option>)}</select></label></div>
              <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create route"}</button>
            </form>
          </section>
          <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">STUDENT ROUTE</div><h2>Assign transport</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submit(event, "/transport/assignments")}>
              <label className="field-label">Student<select name="student_id" required defaultValue=""><option value="" disabled>Select student</option>{transport.students.map((person) => <option key={person.id} value={person.id}>{personName(person)} · {person.admission_number}</option>)}</select></label>
              <label className="field-label">Route<select name="transport_route_id" required defaultValue=""><option value="" disabled>Select route</option>{transport.routes.filter((route) => route.status === "active").map((route) => <option key={route.id} value={route.id}>{route.name}</option>)}</select></label>
              <div className="form-two-col"><label className="field-label">Pickup point<input name="pickup_point" maxLength={180} /></label><label className="field-label">Starts on<input name="starts_on" type="date" required /></label></div>
              <button className="button button-primary" disabled={saving || !transport.routes.length || !transport.students.length}>{saving ? "Saving…" : "Assign student"}</button>
            </form>
          </section>
        </div>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">FLEET REGISTER</div><h2>Vehicle status and capacity</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Vehicle</th><th>Description / driver</th><th>Capacity</th><th>Status</th><th>Update</th></tr></thead><tbody>
            {transport.vehicles.map((vehicle) => <tr key={vehicle.id}><td>{vehicle.registration_number}</td><td>{vehicle.description ?? "—"}<small>{vehicle.driver ? personName(vehicle.driver) : "No driver assigned"}</small></td><td>{vehicle.capacity}</td><td><span className="status-badge">{vehicle.status}</span></td><td><form className="inline-form" onSubmit={(event) => void submit(event, `/transport/vehicles/${vehicle.id}`, "PATCH", true)}><input type="hidden" name="description" value={vehicle.description ?? ""} /><input type="number" name="capacity" aria-label={`Capacity for ${vehicle.registration_number}`} min="1" defaultValue={vehicle.capacity} required /><select name="driver_employee_id" defaultValue={vehicle.driver_employee_id ?? ""}><option value="">Unassigned</option>{transport.employees.map((person) => <option key={person.id} value={person.id}>{personName(person)}</option>)}</select><select name="status" defaultValue={vehicle.status}><option value="active">Active</option><option value="maintenance">Maintenance</option><option value="inactive">Out of service</option></select><button className="button button-secondary" disabled={saving}>Save</button></form></td></tr>)}
            {!transport.vehicles.length && <tr><td colSpan={5}>No vehicles registered.</td></tr>}
          </tbody></table></div>
        </section>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">ROUTE REGISTER</div><h2>Routes and assigned riders</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Route</th><th>Stops</th><th>Vehicle</th><th>Riders / fee</th><th>Status</th><th>Update</th></tr></thead><tbody>
            {transport.routes.map((route) => <tr key={route.id}><td>{route.name}</td><td>{route.stops}</td><td>{route.vehicle?.registration_number ?? "Unassigned"}</td><td>{route.active_students} · {money(route.monthly_fee)}</td><td><span className="status-badge">{route.status}</span></td><td><form className="inline-form" onSubmit={(event) => void submit(event, `/transport/routes/${route.id}`, "PATCH", true)}><input type="hidden" name="name" value={route.name} /><input type="hidden" name="stops" value={route.stops} /><input type="hidden" name="monthly_fee" value={route.monthly_fee} /><select name="vehicle_id" defaultValue={route.vehicle?.id ?? ""}><option value="">No vehicle</option>{transport.vehicles.filter((vehicle) => vehicle.status === "active").map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.registration_number}</option>)}</select><select name="status" defaultValue={route.status}><option value="active">Active</option><option value="inactive">Inactive</option></select><button className="button button-secondary" disabled={saving}>Save</button></form></td></tr>)}
            {!transport.routes.length && <tr><td colSpan={6}>No routes created.</td></tr>}
          </tbody></table></div>
        </section>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">ACTIVE ASSIGNMENTS</div><h2>Students using transport</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Student</th><th>Route</th><th>Pickup point</th><th>Monthly fee</th><th>Action</th></tr></thead><tbody>{transport.assignments.map((entry) => <tr key={entry.id}><td>{personName(entry.student)}</td><td>{entry.route.name}</td><td>{entry.pickup_point ?? "—"}</td><td>{money(entry.route.monthly_fee)}</td><td><button className="button button-secondary" disabled={saving} onClick={() => void action(`/transport/assignments/${entry.id}/end`, "Transport assignment ended.")}>End assignment</button></td></tr>)}{!transport.assignments.length && <tr><td colSpan={5}>No active assignments.</td></tr>}</tbody></table></div>
        </section>
      </>}

      {module === "inventory" && inventory && <>
        <div className="bottom-grid">
          <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">NEW STOCK ITEM</div><h2>Add inventory item</h2></div></div>
            <form className="compact-form" onSubmit={(event) => void submit(event, "/inventory")}>
              <label className="field-label">Item name<input name="name" required maxLength={180} /></label>
              <div className="form-two-col"><label className="field-label">SKU<input name="sku" maxLength={80} /></label><label className="field-label">Category<input name="category" maxLength={100} /></label></div>
              <div className="form-two-col"><label className="field-label">Unit<input name="unit" required maxLength={30} defaultValue="unit" /></label><label className="field-label">Opening stock<input name="quantity" type="number" min="0" required defaultValue="0" /></label><label className="field-label">Reorder level<input name="reorder_level" type="number" min="0" required defaultValue="0" /></label></div>
              <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Add item"}</button>
            </form>
          </section>
        </div>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">STOCK LEVELS</div><h2>Inventory</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>SKU</th><th>Quantity</th><th>Reorder level</th><th>Stock movement</th></tr></thead><tbody>{inventory.items.map((item) => <tr key={item.id}><td>{item.name} <small>{item.unit}</small></td><td>{item.sku ?? "—"}</td><td><span className={item.quantity <= item.reorder_level ? "status-badge status-badge-warning" : "status-badge"}>{item.quantity}</span></td><td>{item.reorder_level}</td><td><form className="inline-form" onSubmit={(event) => void submit(event, `/inventory/${item.id}/transactions`)}><select name="type" aria-label="Stock movement type"><option value="received">Receive</option><option value="issued">Issue</option><option value="adjustment">Adjustment (+)</option></select><input name="quantity" type="number" min="1" required aria-label="Quantity" /><input name="description" maxLength={180} placeholder="Note (optional)" aria-label="Description" /><button className="button button-secondary" disabled={saving}>Save</button></form></td></tr>)}{!inventory.items.length && <tr><td colSpan={5}>No inventory items.</td></tr>}</tbody></table></div>
        </section>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">STOCK LEDGER</div><h2>Recent transactions</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Quantity</th><th>Note</th></tr></thead><tbody>{inventory.transactions.map((transaction) => <tr key={transaction.id}><td>{formatDateTime(transaction.created_at)}</td><td>{transaction.item.name}</td><td>{transaction.type}</td><td>{transaction.quantity}</td><td>{transaction.description ?? "—"}</td></tr>)}{!inventory.transactions.length && <tr><td colSpan={5}>No stock movements.</td></tr>}</tbody></table></div>
        </section>
      </>}

      {module === "notifications" && notifications && <>
        <div className="notice notice-info" role="note">Send to one recipient at a time. Use international E.164 format for SMS and WhatsApp numbers. WhatsApp messages outside the active conversation window may require an approved template.</div>
        <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">NEW MESSAGE</div><h2>Send a notification</h2></div></div>
          <form className="compact-form" onSubmit={(event) => void submit(event, "/notifications")}>
            <div className="form-two-col"><label className="field-label">Channel<select name="channel" required defaultValue="email"><option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select></label><label className="field-label">Recipient<input name="recipient" required maxLength={255} placeholder="email@example.com or +15551234567" /></label></div>
            <label className="field-label">Subject (email only)<input name="subject" maxLength={180} /></label>
            <label className="field-label">Message<textarea name="message" required maxLength={5000} rows={5} /></label>
            <button className="button button-primary" disabled={saving}>{saving ? "Sending…" : "Send message"}</button>
          </form>
        </section>
        <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">DELIVERY HISTORY</div><h2>Recent messages</h2></div></div>
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Created</th><th>Channel</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Details</th></tr></thead><tbody>{rows<Delivery>(notifications.data?.data).map((delivery) => <tr key={delivery.id}><td>{formatDateTime(delivery.created_at)}</td><td>{delivery.channel}</td><td>{delivery.recipient}</td><td>{delivery.subject ?? "—"}</td><td>{delivery.status}</td><td>{delivery.failure_reason ?? (delivery.sent_at ? `Sent ${formatDateTime(delivery.sent_at)}` : "—")}</td></tr>)}{!rows<Delivery>(notifications.data?.data).length && <tr><td colSpan={6}>No delivery attempts recorded.</td></tr>}</tbody></table></div>
        </section>
      </>}
    </div>
  );
}
