import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const webRoot = path.resolve(__dirname, '..');
const publicDir = path.resolve(webRoot, 'public');
const defaultBrandPath = path.resolve(webRoot, 'app/brand/default-brand.ts');

console.log('--- Brand Assets Validation ---');
console.log('Public directory:', publicDir);
console.log('default-brand.ts:', defaultBrandPath);

if (!fs.existsSync(defaultBrandPath)) {
  console.error('Error: default-brand.ts not found!');
  process.exit(1);
}

const content = fs.readFileSync(defaultBrandPath, 'utf8');

// Extract all local paths starting with /brand or /favicon
const pathRegex = /path:\s*['"]([^'"]+)['"]/g;
const paths = [];
let match;
while ((match = pathRegex.exec(content)) !== null) {
  paths.push(match[1]);
}

const faviconRegex = /favicon:\s*['"]([^'"]+)['"]/g;
while ((match = faviconRegex.exec(content)) !== null) {
  paths.push(match[1]);
}

console.log('Found brand asset paths to validate:', paths);

// 1. Validate paths, checking for duplicates and directory escapes
const visitedPaths = new Set();
for (const p of paths) {
  if (visitedPaths.has(p)) {
    console.error(`Error: Semantic duplicate path found in default-brand.ts: ${p}`);
    process.exit(1);
  }
  visitedPaths.add(p);

  const absoluteAssetPath = path.resolve(publicDir, p.replace(/^\//, ''));
  
  if (!absoluteAssetPath.startsWith(publicDir)) {
    console.error(`Error: Asset path escapes public directory: ${p}`);
    process.exit(1);
  }

  if (!fs.existsSync(absoluteAssetPath)) {
    console.error(`Error: Brand asset file does not exist: ${p} (resolved: ${absoluteAssetPath})`);
    process.exit(1);
  }
}

// 2. Validate block requirements (width/height OR aspectRatio)
const lines = content.split('\n');
let currentBlock = null;
let currentBlockLines = [];

for (const line of lines) {
  if (line.includes('{') && (
    line.includes('wordmark') || 
    line.includes('compactMark') || 
    line.includes('defaultOpenGraphImage') || 
    line.includes('homepageHeroImage') || 
    line.includes('placePlaceholder') || 
    line.includes('mapUnavailableIllustration') || 
    line.includes('noResultsIllustration') || 
    line.includes('userAvatarPlaceholder') || 
    line.includes('parks:') || 
    line.includes('cafes:') || 
    line.includes('playrooms:') || 
    line.includes('museums:') || 
    line.includes('outdoor:') || 
    line.includes('generic:')
  )) {
    currentBlock = line.trim();
    currentBlockLines = [line];
  } else if (currentBlock) {
    currentBlockLines.push(line);
    if (line.includes('}')) {
      const blockStr = currentBlockLines.join('\n');
      const hasPath = blockStr.includes('path:');
      const hasWidth = blockStr.includes('width:');
      const hasHeight = blockStr.includes('height:');
      const hasAspect = blockStr.includes('aspectRatio:');

      if (hasPath) {
        // If it is a category mapping block, it inherits generic dimensions so it is optional,
        // but for main illustrations, we require either width/height or aspectRatio.
        const isCategoryMapping = currentBlock.includes('parks:') || 
                                  currentBlock.includes('cafes:') || 
                                  currentBlock.includes('playrooms:') || 
                                  currentBlock.includes('museums:') || 
                                  currentBlock.includes('outdoor:') || 
                                  currentBlock.includes('generic:');
        
        if (!isCategoryMapping && !hasAspect && (!hasWidth || !hasHeight)) {
          console.error(`Error: Brand manifest block "${currentBlock}" must declare either "width" & "height" OR "aspectRatio"!`);
          console.error(blockStr);
          process.exit(1);
        }
      }
      currentBlock = null;
    }
  }
}

console.log('✓ Brand assets validation successful. All paths exist, do not escape public directory, have dimensions/aspectRatio, and have no duplicates.');
process.exit(0);
