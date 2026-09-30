import { type MatrixRow, type RoleOption } from '@/components/app/users/types';
import { SectionCard } from '@/components/shared/section-card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Check, Minus, Store } from 'lucide-react';
import { Fragment } from 'react';

/** Read-only "what each role can do", from the same permission map the server enforces. */
export function RoleMatrix({ roles, rows }: { roles: RoleOption[]; rows: MatrixRow[] }) {
    const groups = rows.reduce<Record<string, MatrixRow[]>>((acc, row) => {
        (acc[row.group] ??= []).push(row);
        return acc;
    }, {});

    return (
        <div className="grid gap-6">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {roles.map((role) => (
                    <div key={role.value} className="bg-card shadow-card rounded-xl border p-4">
                        <p className="text-sm font-semibold">{role.label}</p>
                        <p className="text-muted-foreground mt-1 text-sm">{role.help}</p>
                    </div>
                ))}
            </div>

            <SectionCard flush title="What each role can do" description="Roles are fixed. Choose the closest one for each person.">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="min-w-48 pl-5">Permission</TableHead>
                                {roles.map((role) => (
                                    <TableHead key={role.value} className="text-center">
                                        {role.label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {Object.entries(groups).map(([group, groupRows]) => (
                                <Fragment key={group}>
                                    <TableRow className="bg-subtle hover:bg-subtle">
                                        <TableCell
                                            colSpan={roles.length + 1}
                                            className="text-muted-foreground py-2 pl-5 text-xs font-semibold tracking-wide uppercase"
                                        >
                                            {group}
                                        </TableCell>
                                    </TableRow>
                                    {groupRows.map((row) => (
                                        <TableRow key={row.key}>
                                            <TableCell className="pl-5">{row.label}</TableCell>
                                            {roles.map((role) => (
                                                <TableCell key={role.value} className="text-center">
                                                    {row.roles[role.value] ? (
                                                        <Check className="text-success mx-auto size-4" aria-label="Yes" />
                                                    ) : (
                                                        <Minus className="text-muted-foreground/60 mx-auto size-4" aria-label="No" />
                                                    )}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </SectionCard>

            <div className="bg-info-soft text-info-foreground flex gap-3 rounded-xl p-4 text-sm">
                <Store className="mt-0.5 size-4 shrink-0" aria-hidden />
                <p>
                    <span className="font-semibold">Shop managers.</span> Anyone except an owner can be limited to one shop. They keep their role’s
                    permissions but only see that shop’s sales, stock and figures.
                </p>
            </div>
        </div>
    );
}
