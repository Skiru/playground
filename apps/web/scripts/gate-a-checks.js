import fs from "fs";
import path from "path";

const WEB_APP_DIR = path.resolve("apps/web/app");

const IGNORED_PATHS = [
  "apps/web/app/theme",
  "apps/web/app/brand",
];

const ALLOWED_HEX_COLORS = [
  // If there are any allowed technical hex colors, list them here
  "#d45132", // maplibre marker color in MapExplorer.tsx
];

function getAllFiles(dir, ext) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach((file) => {
    const filePath = path.join(dir, file);
    const stat = fs.statSync(filePath);
    if (stat && stat.isDirectory()) {
      results = results.concat(getAllFiles(filePath, ext));
    } else if (file.endsWith(ext)) {
      results.push(filePath);
    }
  });
  return results;
}

function checkHardcodedColors() {
  const cssFiles = getAllFiles(WEB_APP_DIR, ".css");
  const tsxFiles = getAllFiles(WEB_APP_DIR, ".tsx");
  const files = [...cssFiles, ...tsxFiles];

  const hexRegex = /#([0-9a-fA-F]{3,8})\b/g;
  let failures = 0;

  files.forEach((file) => {
    const relativePath = path.relative(process.cwd(), file);
    if (IGNORED_PATHS.some((p) => relativePath.startsWith(p))) {
      return;
    }

    const content = fs.readFileSync(file, "utf8");
    let match;
    while ((match = hexRegex.exec(content)) !== null) {
      const color = match[0];
      if (ALLOWED_HEX_COLORS.includes(color.toLowerCase())) {
        continue;
      }
      console.error(`Error: Hardcoded color '${color}' found in ${relativePath}`);
      failures++;
    }
  });

  return failures;
}

function checkUserFacingLiterals() {
  const tsxFiles = getAllFiles(path.join(WEB_APP_DIR, "routes"), ".tsx")
    .concat(getAllFiles(path.join(WEB_APP_DIR, "components"), ".tsx"));

  // Match text nodes between JSX tags that contain Polish characters or alphabetic words
  // e.g., >Katalog miejsc< or <h2>Informacje</h2> or <p>Brak wyników</p>
  // Exclude lines with only braces, whitespace, variables, paths, classNames, IDs, or comments.
  const polishLiteralRegex = />([^<{}>]*[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ]+[^<{}>]*)<\//g;
  let failures = 0;

  tsxFiles.forEach((file) => {
    const relativePath = path.relative(process.cwd(), file);
    if (file.endsWith(".test.tsx") || relativePath.includes("SiteHeader.tsx")) {
      // Exclude tests and SiteHeader (which is already migrated anyway)
      return;
    }

    const content = fs.readFileSync(file, "utf8");
    let match;
    while ((match = polishLiteralRegex.exec(content)) !== null) {
      const rawText = match[1].trim();
      if (!rawText) continue;
      // Allowlist technical values like "pl" or route params
      if (["pl", "relevance", "true", "any"].includes(rawText.toLowerCase())) {
        continue;
      }
      console.error(`Error: Potential user-facing literal string '${rawText}' found in JSX of ${relativePath}`);
      failures++;
    }
  });

  return failures;
}

function main() {
  console.log("Running Gate A Automated Checks...");
  const colorFailures = checkHardcodedColors();
  const literalFailures = checkUserFacingLiterals();

  if (colorFailures > 0 || literalFailures > 0) {
    console.error(`Gate A checks FAILED: ${colorFailures} color issues, ${literalFailures} literal string issues.`);
    process.exit(1);
  } else {
    console.log("✓ Gate A Automated Checks PASSED.");
    process.exit(0);
  }
}

main();
