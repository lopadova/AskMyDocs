#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        // All backend calls go through the HTTP plugin (Rust side) so the
        // webview never performs a cross-origin fetch — no CORS config needed.
        .plugin(tauri_plugin_http::init())
        // Persists the Bearer token + local conversation threads on disk.
        .plugin(tauri_plugin_store::Builder::new().build())
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
