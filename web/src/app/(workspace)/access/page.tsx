"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { hasPermission, type Permission, type Role, type User } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type AccessUser = User;
type NewUserForm = { name: string; email: string; password: string; password_confirmation: string; role_ids: number[] };

export default function AccessPage() {
  const { user: currentUser } = useSession();
  const [roles, setRoles] = useState<Role[]>([]);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [users, setUsers] = useState<AccessUser[]>([]);
  const [form, setForm] = useState<NewUserForm>({ name: "", email: "", password: "", password_confirmation: "", role_ids: [] });
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [saving, setSaving] = useState(false);

  const canViewRoles = hasPermission(currentUser, "roles.view");
  const canEditRoles = hasPermission(currentUser, "roles.edit");
  const canViewUsers = hasPermission(currentUser, "users.view");
  const canEditUsers = hasPermission(currentUser, "users.edit");
  const canCreateUsers = hasPermission(currentUser, "users.create");

  const load = useCallback(async () => {
    setError("");
    const jobs: Promise<void>[] = [];
    if (canViewRoles) {
      jobs.push(apiRequest<{ data: Role[]; permissions: Permission[] }>("/access/roles").then((response) => {
        setRoles(response.data);
        setPermissions(response.permissions);
      }));
    }
    if (canViewUsers) {
      jobs.push(apiRequest<{ data: AccessUser[]; roles: Role[] }>("/access/users").then((response) => {
        setUsers(response.data);
        if (!canViewRoles) setRoles(response.roles);
      }));
    }
    try {
      await Promise.all(jobs);
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }, [canViewRoles, canViewUsers]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function saveUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/access/users", { method: "POST", body: form });
      setForm({ name: "", email: "", password: "", password_confirmation: "", role_ids: [] });
      setNotice("User account created.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function updateRole(role: Role, permissionId: number, checked: boolean) {
    const permissionIds = new Set(role.permissions.map((item) => item.id));
    if (checked) permissionIds.add(permissionId);
    else permissionIds.delete(permissionId);
    setSaving(true);
    setError("");
    try {
      const result = await apiRequest<{ data: Role }>(`/access/roles/${role.id}/permissions`, {
        method: "PUT",
        body: { permission_ids: [...permissionIds] },
      });
      setRoles((current) => current.map((item) => item.id === role.id ? result.data : item));
      setNotice(`Permissions updated for ${role.label}.`);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function updateUserRoles(accessUser: AccessUser, roleId: number, checked: boolean) {
    const roleIds = new Set(accessUser.roles.map((role) => role.id));
    if (checked) roleIds.add(roleId);
    else roleIds.delete(roleId);
    setSaving(true);
    setError("");
    try {
      const result = await apiRequest<{ data: AccessUser }>(`/access/users/${accessUser.id}/roles`, {
        method: "PUT",
        body: { role_ids: [...roleIds] },
      });
      setUsers((current) => current.map((item) => item.id === accessUser.id ? result.data : item));
      setNotice(`Roles updated for ${accessUser.name}.`);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function toggleUserStatus(accessUser: AccessUser) {
    setSaving(true);
    setError("");
    try {
      const result = await apiRequest<{ data: AccessUser }>(`/access/users/${accessUser.id}/status`, {
        method: "PUT",
        body: { is_active: !accessUser.is_active },
      });
      setUsers((current) => current.map((item) => item.id === accessUser.id ? { ...item, ...result.data } : item));
      setNotice(`${accessUser.name} is now ${result.data.is_active ? "active" : "inactive"}.`);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">SCHOOL SETUP / SECURITY</div><h1>Access control<span className="heading-period">.</span></h1><p>Give each team member the access they need, and no more.</p></div>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreateUsers && <form className="surface-card access-create" onSubmit={saveUser}>
        <div className="card-heading"><div><div className="eyebrow">TEAM ACCESS</div><h2>Add a user account</h2></div></div>
        <div className="settings-grid">
          <label className="field-label">Full name<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label>
          <label className="field-label">Email address<input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} required /></label>
          <label className="field-label">Temporary password<input type="password" minLength={12} autoComplete="new-password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} required /></label>
          <label className="field-label">Confirm password<input type="password" minLength={12} autoComplete="new-password" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} required /></label>
        </div>
        <fieldset className="role-picker"><legend>Assign roles</legend>{roles.filter((role) => role.name !== "super-admin").map((role) => <label className="checkbox-line" key={role.id}><input type="checkbox" checked={form.role_ids.includes(role.id)} onChange={(event) => setForm((value) => ({ ...value, role_ids: event.target.checked ? [...value.role_ids, role.id] : value.role_ids.filter((id) => id !== role.id) }))} /> {role.label}</label>)}</fieldset>
        <button className="button button-primary" disabled={saving || form.role_ids.length === 0} type="submit">{saving ? "Creating…" : "Create account"} <span aria-hidden="true">→</span></button>
      </form>}

      {canViewUsers && <section className="surface-card access-section">
        <div className="card-heading"><div><div className="eyebrow">PEOPLE & ROLES</div><h2>User accounts</h2></div><span className="count-pill">{users.length}</span></div>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>User</th><th>Assigned roles</th><th>Status</th>{canEditUsers && <th>Action</th>}</tr></thead><tbody>
          {users.map((accessUser) => <tr key={accessUser.id}><td><strong>{accessUser.name}</strong><small>{accessUser.email}</small></td><td><div className="role-checkboxes">{roles.map((role) => <label className="checkbox-line compact" key={role.id}><input type="checkbox" checked={accessUser.roles.some((item) => item.id === role.id)} disabled={!canEditUsers || saving || (accessUser.id === currentUser.id && role.name === "super-admin")} onChange={(event) => void updateUserRoles(accessUser, role.id, event.target.checked)} />{role.label}</label>)}</div></td><td><span className={accessUser.is_active ? "active-badge" : "inactive-badge"}>{accessUser.is_active ? "Active" : "Inactive"}</span></td>{canEditUsers && <td><button className="small-action" disabled={saving || accessUser.id === currentUser.id} onClick={() => void toggleUserStatus(accessUser)}>{accessUser.is_active ? "Deactivate" : "Activate"}</button></td>}</tr>)}
          {users.length === 0 && <tr><td colSpan={canEditUsers ? 4 : 3} className="table-empty">No user accounts to show.</td></tr>}
        </tbody></table></div>
      </section>}

      {canViewRoles && <section className="surface-card access-section">
        <div className="card-heading"><div><div className="eyebrow">ROLE PERMISSIONS</div><h2>Permission matrix</h2><p className="muted-copy">Changes apply to all users assigned to that role.</p></div></div>
        <div className="table-scroll"><table className="data-table permission-table"><thead><tr><th>Role</th><th>Permission</th>{permissions.map((permission) => <th className="vertical-heading" key={permission.id}><span>{permission.name}</span></th>)}</tr></thead><tbody>
          {roles.filter((role) => role.name !== "super-admin").map((role) => <tr key={role.id}><td><strong>{role.label}</strong></td><td>{role.permissions.length} enabled</td>{permissions.map((permission) => <td key={permission.id}><input aria-label={`${role.label}: ${permission.label}`} type="checkbox" checked={role.permissions.some((item) => item.id === permission.id)} disabled={!canEditRoles || saving} onChange={(event) => void updateRole(role, permission.id, event.target.checked)} /></td>)}</tr>)}
        </tbody></table></div>
        {!canEditRoles && <p className="muted-copy">You have read-only access to role permissions.</p>}
      </section>}
      {!canViewRoles && !canViewUsers && <div className="surface-card empty-state">Your account does not have permission to manage access.</div>}
    </div>
  );
}
