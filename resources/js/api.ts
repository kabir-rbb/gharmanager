export async function api(path: string, method = "GET", body?: unknown) {
    const csrf = decodeURIComponent(
        document.cookie
            .split("; ")
            .find((v) => v.startsWith("XSRF-TOKEN="))
            ?.slice(11) || "",
    );
    const response = await fetch(path, {
        method,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-XSRF-TOKEN": csrf,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = response.status === 204 ? null : await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(data.errors || {})
                .flat()
                .join(" ") ||
                data.message ||
                "Request failed",
        );
    return data;
}
