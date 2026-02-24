const BLOCK_TYPES = {
  STATIC: 'static',
  DYNAMIC: 'dynamic',
  MIXED: 'mixed'
};

const DATA_BINDING_ATTR_PATTERNS = [/^v-/, /^:/, /^ng-/, /^x-/, /^wire:/, /^data-bind/i];
const DATA_BINDING_VALUE_PATTERNS = [
  /\{\{\s*[^}]+\s*\}\}/,
  /\$\{\s*[^}]+\s*\}/,
  /\bthis\.[a-zA-Z_$]/
];
const CMS_PATTERNS = [/\{\{\s*[^}]+\s*\}\}/, /\[[^\]]+\]/, /__\w+__/, /%[\w_]+%/, /<\?php/i];
const QUERY_PATTERNS = [/query|filter|search|sort|pagination/i, /wp:|data:|\.php\?/i];
const FIELD_TOKEN_PATTERN =
  /\{\{\s*([a-zA-Z][\w.-]*)\s*\}\}|\[\s*([a-zA-Z][\w-]*)[^\]]*\]|%([A-Z0-9_]+)%/g;

export const BlockType = Object.freeze(BLOCK_TYPES);

export function identifyBlockType(input) {
  const normalized = normalizeInput(input);
  const reasons = [];

  const hasDataBinding = checkDataBinding(normalized.attributes);
  const hasCMSContent = checkCMSContent(normalized.innerHTML);
  const hasRepeaterPattern = checkRepeaterPattern(normalized.children);
  const hasQueryPattern = checkQueryPattern(normalized.html);

  if (hasDataBinding) {
    reasons.push('data_binding');
  }
  if (hasQueryPattern) {
    reasons.push('query_pattern');
  }
  if (hasRepeaterPattern) {
    reasons.push('repeater_pattern');
  }
  if (hasCMSContent) {
    reasons.push('cms_placeholder');
  }

  let type = BLOCK_TYPES.STATIC;
  if (hasDataBinding || hasQueryPattern) {
    type = BLOCK_TYPES.DYNAMIC;
  } else if (hasRepeaterPattern || hasCMSContent) {
    type = BLOCK_TYPES.MIXED;
  }

  return {
    type,
    confidence: calculateConfidence(type, reasons),
    reasons,
    suggested_fields: extractSuggestedFields(normalized.html, normalized.innerHTML)
  };
}

function normalizeInput(input) {
  if (isElement(input)) {
    return normalizeFromElement(input);
  }

  const source = input && typeof input === 'object' ? input : {};
  const html = asString(source.html);
  const innerHTML = asString(source.innerHTML || html);

  return {
    html,
    innerHTML,
    attributes: normalizeAttributes(source.attributes),
    children: normalizeChildren(source.children)
  };
}

function isElement(value) {
  return typeof Element !== 'undefined' && value instanceof Element;
}

function normalizeFromElement(element) {
  const attrs = Array.from(element.attributes || []).map(({ name, value }) => ({
    name: asString(name),
    value: asString(value)
  }));
  const children = Array.from(element.children || []).map((child) => ({
    tagName: asString(child.tagName).toLowerCase(),
    className: asString(child.className),
    id: asString(child.id)
  }));

  return {
    html: asString(element.outerHTML),
    innerHTML: asString(element.innerHTML),
    attributes: attrs,
    children
  };
}

function normalizeAttributes(attributes) {
  if (Array.isArray(attributes)) {
    return attributes.map((entry) => ({
      name: asString(entry?.name),
      value: asString(entry?.value)
    }));
  }

  if (attributes && typeof attributes === 'object') {
    return Object.entries(attributes).map(([name, value]) => ({
      name: asString(name),
      value: asString(value)
    }));
  }

  return [];
}

function normalizeChildren(children) {
  if (!Array.isArray(children)) {
    return [];
  }

  return children.map((child) => ({
    tagName: asString(child?.tagName).toLowerCase(),
    className: asString(child?.className),
    id: asString(child?.id)
  }));
}

function checkDataBinding(attributes) {
  return attributes.some((attr) => {
    const name = attr.name.trim();
    const value = attr.value.trim();
    return (
      DATA_BINDING_ATTR_PATTERNS.some((pattern) => pattern.test(name)) ||
      DATA_BINDING_VALUE_PATTERNS.some((pattern) => pattern.test(value))
    );
  });
}

function checkCMSContent(innerHTML) {
  return CMS_PATTERNS.some((pattern) => pattern.test(innerHTML));
}

function checkRepeaterPattern(children) {
  if (children.length < 2) {
    return false;
  }

  const signatures = children.map((child) =>
    [child.tagName || '*', child.className || '', child.id || ''].join('|')
  );
  const [first] = signatures;
  return signatures.every((signature) => signature === first);
}

function checkQueryPattern(html) {
  return QUERY_PATTERNS.some((pattern) => pattern.test(html));
}

function extractSuggestedFields(...sources) {
  const value = sources.filter(Boolean).join('\n');
  const fields = new Set();
  let match;

  while ((match = FIELD_TOKEN_PATTERN.exec(value)) !== null) {
    const token = match[1] || match[2] || match[3];
    if (token) {
      fields.add(normalizeFieldName(token));
    }
  }

  return Array.from(fields).filter(Boolean);
}

function normalizeFieldName(fieldName) {
  return asString(fieldName)
    .trim()
    .replace(/\./g, '_')
    .replace(/-/g, '_')
    .replace(/[^\w]/g, '')
    .toLowerCase();
}

function calculateConfidence(type, reasons) {
  if (type === BLOCK_TYPES.STATIC) {
    return 0.82;
  }

  const base = type === BLOCK_TYPES.DYNAMIC ? 0.72 : 0.66;
  const bonus = Math.min(reasons.length * 0.06, 0.2);
  return Number(Math.min(base + bonus, 0.95).toFixed(2));
}

function asString(value) {
  return typeof value === 'string' ? value : value == null ? '' : String(value);
}

