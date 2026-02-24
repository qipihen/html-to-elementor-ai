const OPENROUTER_CHAT_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
const OPENROUTER_MODELS_ENDPOINT = 'https://openrouter.ai/api/v1/models';

const DEFAULT_CONFIG = {
  provider: 'openrouter',
  model: 'gpt-4o-mini',
  apiKey: ''
};

export class AIService {
  constructor(options = {}) {
    this.fetcher = options.fetcher || globalThis.fetch?.bind(globalThis);
    this.chatEndpoint = options.chatEndpoint || OPENROUTER_CHAT_ENDPOINT;
    this.modelsEndpoint = options.modelsEndpoint || OPENROUTER_MODELS_ENDPOINT;
    this.config = null;

    if (typeof this.fetcher !== 'function') {
      throw new Error('A fetch-compatible function is required');
    }
  }

  init(config) {
    this.config = {
      ...DEFAULT_CONFIG,
      ...(config || {})
    };
    return this;
  }

  async optimize(elementData) {
    this.assertConfigured();
    const messages = this.buildMessages(elementData);
    const response = await this.callAPI(messages);
    return this.parseResponse(response);
  }

  buildMessages(elementData = {}) {
    const html = safeString(elementData.html);
    const css = safeString(elementData.css);
    const currentResult = safeString(elementData.currentResult);

    return [
      {
        role: 'system',
        content:
          '你是一个 CSS 和 Elementor 优化专家。请在保持视觉一致的前提下，优化冗余样式并补全布局信息。仅返回 JSON。'
      },
      {
        role: 'user',
        content: [
          '请优化以下 Elementor 转换结果：',
          '',
          '原始 HTML:',
          html,
          '',
          '原始 CSS:',
          css,
          '',
          '当前转换结果:',
          currentResult,
          '',
          '返回字段:',
          '- optimized_settings',
          '- layout_enhancements',
          '- simplified_count',
          '- explanation'
        ].join('\n')
      }
    ];
  }

  async callAPI(messages) {
    this.assertConfigured();

    const response = await this.fetcher(this.chatEndpoint, {
      method: 'POST',
      headers: this.buildHeaders(),
      body: JSON.stringify({
        model: this.config.model,
        messages
      })
    });

    if (!response.ok) {
      throw new Error(`OpenRouter request failed with status ${response.status}`);
    }

    return response.json();
  }

  parseResponse(response) {
    const content = response?.choices?.[0]?.message?.content;
    if (typeof content !== 'string' || content.trim().length === 0) {
      throw new Error('OpenRouter response does not contain message content');
    }

    const jsonText = extractJsonText(content);

    try {
      return JSON.parse(jsonText);
    } catch (error) {
      throw new Error(`Failed to parse AI JSON response: ${error.message}`);
    }
  }

  async testConnection() {
    try {
      this.assertConfigured();
      const response = await this.fetcher(this.modelsEndpoint, {
        method: 'GET',
        headers: {
          Authorization: `Bearer ${this.config.apiKey}`
        }
      });
      return Boolean(response.ok);
    } catch {
      return false;
    }
  }

  buildHeaders() {
    return {
      Authorization: `Bearer ${this.config.apiKey}`,
      'Content-Type': 'application/json',
      'HTTP-Referer': 'https://chrome.google.com/webstore'
    };
  }

  assertConfigured() {
    if (!this.config) {
      throw new Error('AI service has not been initialized');
    }
    if (String(this.config.apiKey || '').trim().length === 0) {
      throw new Error('OpenRouter API key is missing');
    }
  }
}

function extractJsonText(content) {
  const fencedMatch = content.match(/```(?:json)?\s*([\s\S]*?)```/i);
  const candidate = fencedMatch ? fencedMatch[1].trim() : content.trim();

  const firstBrace = candidate.indexOf('{');
  const lastBrace = candidate.lastIndexOf('}');
  if (firstBrace === -1 || lastBrace === -1 || firstBrace > lastBrace) {
    throw new Error('No JSON object found in AI response');
  }

  return candidate.slice(firstBrace, lastBrace + 1);
}

function safeString(value) {
  return typeof value === 'string' ? value : value == null ? '' : String(value);
}

