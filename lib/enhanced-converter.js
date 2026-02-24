function safeString(value) {
  return typeof value === 'string' ? value : value == null ? '' : String(value);
}

function isObject(value) {
  return Object.prototype.toString.call(value) === '[object Object]';
}

function deepMerge(base, override) {
  if (!isObject(base) || !isObject(override)) {
    return structuredClone(override);
  }

  const result = structuredClone(base);
  for (const [key, value] of Object.entries(override)) {
    if (isObject(value) && isObject(result[key])) {
      result[key] = deepMerge(result[key], value);
      continue;
    }
    result[key] = structuredClone(value);
  }

  return result;
}

export class EnhancedConverter {
  constructor({ aiService, configManager, logger } = {}) {
    if (!aiService || typeof aiService.optimize !== 'function' || typeof aiService.init !== 'function') {
      throw new Error('EnhancedConverter requires an aiService with init() and optimize()');
    }

    if (!configManager || typeof configManager.getConfig !== 'function') {
      throw new Error('EnhancedConverter requires a configManager with getConfig()');
    }

    this.aiService = aiService;
    this.configManager = configManager;
    this.logger = logger || console;
  }

  async enhanceWithAI(elementData = {}, baseResult = {}, options = {}) {
    const aiConfig = await this.resolveAIConfig(options);
    const shouldUseAI = Boolean(aiConfig?.enabled && safeString(aiConfig?.apiKey).trim().length > 0);

    if (!shouldUseAI) {
      return {
        success: true,
        used_ai: false,
        data: structuredClone(baseResult),
        ai: null
      };
    }

    try {
      this.aiService.init({
        provider: aiConfig.provider || 'openrouter',
        model: aiConfig.model || 'gpt-4o-mini',
        apiKey: aiConfig.apiKey
      });

      const aiResult = await this.aiService.optimize({
        html: safeString(elementData.html),
        css: safeString(elementData.css),
        currentResult: JSON.stringify(baseResult)
      });

      const optimizedSettings = isObject(aiResult?.optimized_settings) ? aiResult.optimized_settings : {};
      const optimizedResult = deepMerge(baseResult, {
        settings: {
          ...(baseResult?.settings || {}),
          ...optimizedSettings
        }
      });

      return {
        success: true,
        used_ai: true,
        data: optimizedResult,
        ai: aiResult
      };
    } catch (error) {
      this.logger?.error?.('AI enhancement failed, fallback to base result', error);

      return {
        success: false,
        used_ai: false,
        data: structuredClone(baseResult),
        ai: null,
        error: error instanceof Error ? error.message : 'AI enhancement failed'
      };
    }
  }

  async resolveAIConfig(options = {}) {
    if (isObject(options.ai)) {
      return {
        enabled: Boolean(options.ai.enabled),
        provider: options.ai.provider || 'openrouter',
        model: options.ai.model || 'gpt-4o-mini',
        apiKey: safeString(options.ai.apiKey)
      };
    }

    const config = await this.configManager.getConfig();
    const ai = config?.ai || {};
    return {
      enabled: Boolean(ai.enabled),
      provider: ai.provider || 'openrouter',
      model: ai.model || 'gpt-4o-mini',
      apiKey: safeString(ai.apiKey)
    };
  }
}

