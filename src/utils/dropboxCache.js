// Keep scanned Dropbox notes between visits, so reopening the feed re-reads only what changed.
const DATABASE = 'textviewer-feed';
const STORE = 'folders';
const VERSION = 1;

function openDatabase() {
  return new Promise((resolve, reject) => {
    if (!window.indexedDB) return reject(new Error('No IndexedDB'));
    const request = window.indexedDB.open(DATABASE, VERSION);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE);
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
    request.onblocked = () => reject(new Error('IndexedDB blocked'));
  });
}

function transact(database, mode, run) {
  return new Promise((resolve, reject) => {
    const transaction = database.transaction(STORE, mode);
    const request = run(transaction.objectStore(STORE));
    transaction.oncomplete = () => resolve(request?.result);
    transaction.onerror = () => reject(transaction.error);
    transaction.onabort = () => reject(transaction.error);
  });
}

// A private window, cleared site data, or a blocked store all read as "nothing cached yet".
export async function readCache(key) {
  try {
    const database = await openDatabase();
    const stored = await transact(database, 'readonly', store => store.get(key));
    database.close();
    return new Map(stored?.items || []);
  } catch {
    return new Map();
  }
}

export async function writeCache(key, cache) {
  try {
    const database = await openDatabase();
    // Temporary links expire, so only durable fields are kept.
    const items = [...cache].filter(([, item]) => item.type !== 'pdf');
    await transact(database, 'readwrite', store => store.put({ items, saved: Date.now() }, key));
    database.close();
  } catch {
    // Caching is an optimisation; the feed still works by re-reading from Dropbox.
  }
}

export async function clearCache(key) {
  try {
    const database = await openDatabase();
    await transact(database, 'readwrite', store => (key ? store.delete(key) : store.clear()));
    database.close();
  } catch {
    // Nothing to clear.
  }
}
