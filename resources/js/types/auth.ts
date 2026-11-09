export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AuthBranch = {
    id: number;
    name: string;
};

export type Auth = {
    user: User;
    /** Effective permission names in the current tenant (hide UI with these; the server still authorizes). */
    permissions: string[];
    /** The user's active branches; the branch switcher shows only when there is more than one. */
    branches: AuthBranch[];
    branch_id: number | null;
    /** Display names of the user's roles in the current tenant (sidebar "Active role" card, status bar); optional until the server shares it. */
    roles?: string[];
    [key: string]: unknown;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
