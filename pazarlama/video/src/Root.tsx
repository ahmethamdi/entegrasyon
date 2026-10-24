import { Composition, Still } from "remotion";
import { PazarReels, PazarCover, DURATION_REELS } from "./Reels";

const FPS = 30;

export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition id="PazarReelsTR" component={PazarReels} durationInFrames={Math.round(DURATION_REELS * FPS)} fps={FPS} width={1080} height={1920} />
      <Still id="PazarKapakTR" component={PazarCover} width={1080} height={1920} />
    </>
  );
};
