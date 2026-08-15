export type UatPersona = {
    key: 'super-admin' | 'admin' | 'office' | 'teacher';
    email?: string;
    password?: string;
    available: boolean;
};

function persona(
    key: UatPersona['key'],
    emailVariable: string,
    passwordVariable: string,
): UatPersona {
    const email = process.env[emailVariable]?.trim();
    const password = process.env[passwordVariable];

    return {
        key,
        email,
        password,
        available: Boolean(email && password),
    };
}

export const personas = {
    superAdmin: persona('super-admin', 'UAT_SUPER_ADMIN_EMAIL', 'UAT_SUPER_ADMIN_PASSWORD'),
    admin: persona('admin', 'UAT_ADMIN_EMAIL', 'UAT_ADMIN_PASSWORD'),
    office: persona('office', 'UAT_OFFICE_EMAIL', 'UAT_OFFICE_PASSWORD'),
    teacher: persona('teacher', 'UAT_TEACHER_EMAIL', 'UAT_TEACHER_PASSWORD'),
} as const;

export function firstAvailable(...candidates: UatPersona[]): UatPersona | undefined {
    return candidates.find((candidate) => candidate.available);
}

export const technicalAdministrator = firstAvailable(personas.superAdmin, personas.admin);
export const operationalAdministrator = firstAvailable(personas.office, personas.admin, personas.superAdmin);

export function configuredSecrets(): string[] {
    return Object.values(personas)
        .flatMap((candidate) => [candidate.password])
        .filter((secret): secret is string => Boolean(secret));
}
