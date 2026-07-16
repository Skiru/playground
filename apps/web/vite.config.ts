import { reactRouter } from "@react-router/dev/vite";
import { defineConfig } from "vite";
import "./app/brand/validate";

export default defineConfig({
  plugins: [reactRouter()],
  resolve: {
    tsconfigPaths: true,
  },
});
