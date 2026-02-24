const DEFAULT_CONFIG = {
  ai: {
    enabled: false,
    provider: 'openrouter',
    model: 'gpt-4o-mini',
    apiKey: '',
    autoOptimize: false
  },
  general: {
    highlightElements: true,
    showCaptureHint: true
  }
};

export class ConfigManager {
  static STORAGE_KEY = 'elementor-ai-capture-studio-config';
  static LEGACY_STORAGE_KEYS = ['html-to-elementor-config'];

  constructor(options = {}) {
    this.storage = options.storage || createChromeLocalStorageAdapter();
    this.storageKey = options.storageKey || ConfigManager.STORAGE_KEY;
    this.cache = null;
  }

  static get DEFAULT_CONFIG() {
    return structuredClone(DEFAULT_CONFIG);
  }

  async getConfig() {
    const rawConfig = await this.resolveStoredConfig();
    const merged = mergeDeep(DEFAULT_CONFIG, rawConfig);
    this.cache = merged;
    return structuredClone(merged);
  }

  async setConfig(config) {
    const current = this.cache || (await this.getConfig());
    const merged = mergeDeep(current, config || {});
    await this.storage.set({ [this.storageKey]: merged });
    this.cache = merged;
    return structuredClone(merged);
  }

  async resetConfig() {
    const nextConfig = structuredClone(DEFAULT_CONFIG);
    await this.storage.set({ [this.storageKey]: nextConfig });
    this.cache = nextConfig;
    return structuredClone(nextConfig);
  }

  async isAIEnabled() {
    const config = this.cache || (await this.getConfig());
    return Boolean(config.ai?.enabled && String(config.ai?.apiKey || '').trim());
  }

  async isAutoOptimize() {
    const config = this.cache || (await this.getConfig());
    return Boolean(config.ai?.enabled && config.ai?.autoOptimize);
  }

  async resolveStoredConfig() {
    const stored = await this.storage.get(this.storageKey);
    const currentConfig = stored?.[this.storageKey];
    if (isObject(currentConfig)) {
      return currentConfig;
    }

    for (const legacyKey of ConfigManager.LEGACY_STORAGE_KEYS) {
      const legacyStored = await this.storage.get(legacyKey);
      const legacyConfig = legacyStored?.[legacyKey];
      if (isObject(legacyConfig)) {
        await this.storage.set({ [this.storageKey]: legacyConfig });
        return legacyConfig;
      }
    }

    return {};
  }
}

function createChromeLocalStorageAdapter() {
  if (!globalThis.chrome?.storage?.local) {
    throw new Error('chrome.storage.local is unavailable and no custom storage adapter was provided');
  }

  return {
    get(key) {
      return new Promise((resolve, reject) => {
        chrome.storage.local.get(key, (result) => {
          const runtimeError = chrome.runtime?.lastError;
          if (runtimeError) {
            reject(new Error(runtimeError.message));
            return;
          }
          resolve(result || {});
        });
      });
    },
    set(value) {
      return new Promise((resolve, reject) => {
        chrome.storage.local.set(value, () => {
          const runtimeError = chrome.runtime?.lastError;
          if (runtimeError) {
            reject(new Error(runtimeError.message));
            return;
          }
          resolve();
        });
      });
    }
  };
}

function mergeDeep(base, override) {
  if (!isObject(base)) {
    return structuredClone(override);
  }

  const result = structuredClone(base);
  if (!isObject(override)) {
    return result;
  }

  for (const [key, value] of Object.entries(override)) {
    if (isObject(value) && isObject(result[key])) {
      result[key] = mergeDeep(result[key], value);
      continue;
    }
    result[key] = structuredClone(value);
  }

  return result;
}

function isObject(value) {
  return Object.prototype.toString.call(value) === '[object Object]';
}
