export type Permission = {
  id: number;
  name: string;
  label: string;
};

export type Role = {
  id: number;
  name: string;
  label: string;
  permissions: Permission[];
};

export type User = {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  school?: { id: number; name: string; slug: string } | null;
  roles: Role[];
};

export function hasPermission(user: User, permission: string): boolean {
  return user.roles.some((role) =>
    role.name === "super-admin" || role.permissions.some((item) => item.name === permission),
  );
}
